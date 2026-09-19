<?php

namespace App\Services\Crm;

use App\Support\IdentificacionEnTexto;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Le pide la cédula al cliente antes de dejar entrar la conversación al CRM.
 *
 * Motivo: hoy el agente abre un chat y lo único que sabe es un número de
 * teléfono. Muchos clientes escriben desde el celular de un familiar o desde
 * otra línea, así que el teléfono no alcanza para saber a quién se le está
 * respondiendo ni para abrir su ficha.
 *
 * Reglas de la puerta:
 *
 *  - Conversación nueva → se pregunta cédula y nombre, y los mensajes que
 *    lleguen mientras tanto quedan retenidos (no se pierden).
 *  - Responde con una cédula que existe → se vincula el cliente, se sueltan
 *    los mensajes retenidos y la conversación aparece en el CRM ya identificada.
 *  - Responde con una cédula que no existe → pasa igual al agente, marcada
 *    como sin identificar, para que el agente sepa por qué.
 *  - No logra dar una cédula válida tras N intentos → pasa igual al agente.
 *    Nadie se queda atrapado hablándole a un bot.
 */
class PuertaIdentificacion
{
    /** Resultado: el mensaje sigue su curso normal hacia el CRM. */
    public const PASA = 'pasa';

    /** Resultado: el mensaje quedó retenido, no crear conversación todavía. */
    public const RETIENE = 'retiene';

    private const TABLA = 'crm_identificaciones';

    /** La línea de WhatsApp Web por la que entró el mensaje que se está evaluando. */
    private ?string $instanceId = null;

    public const PREGUNTA_POR_DEFECTO =
        "¡Hola! 👋 Para atenderte y ver tu cuenta necesito identificarte.\n\n" .
        "Respondé este mensaje con tu *número de cédula* y tu *nombre*.\n" .
        "Por ejemplo: 1234567 Juan Pérez";

    private const REINTENTO =
        "No encontré un número de cédula en tu mensaje. 🙏\n" .
        "Escribime solo el *número*, por ejemplo: 1234567";

    /**
     * Decide qué hacer con un mensaje entrante.
     *
     * @param  array  $payload  El webhook completo, para poder reprocesarlo al soltarlo.
     * @return array{accion: string, dni?: ?string, user_id?: ?int, nombre?: ?string, retenidos?: array}
     */
    public function evaluar(int $companyId, string $provider, string $phone, ?string $texto, array $payload, ?string $instanceId = null): array
    {
        // La empresa puede tener varias líneas: se pregunta y se confirma por la
        // MISMA por la que escribió el cliente, no por la principal.
        $this->instanceId = $instanceId;

        $ajustes = $this->ajustes($companyId);

        if (!$ajustes['identificacion_enabled']) {
            return ['accion' => self::PASA];
        }

        $registro = DB::table(self::TABLA)
            ->where('company_id', $companyId)
            ->where('provider', $provider)
            ->where('phone', $phone)
            ->first();

        // Ya resuelto antes: no se vuelve a molestar a esta persona.
        if ($registro && in_array($registro->estado, ['identificado', 'sin_registro'], true)) {
            return [
                'accion'  => self::PASA,
                'dni'     => $registro->dni,
                'user_id' => $registro->user_id ? (int) $registro->user_id : null,
                'nombre'  => $registro->nombre,
            ];
        }

        // Primer contacto
        if (!$registro) {
            return $this->primerContacto($companyId, $provider, $phone, $ajustes, $payload);
        }

        // Está esperando respuesta: se busca la cédula en este mensaje.
        return $this->respuesta($companyId, $provider, $phone, $texto, $payload, $registro, $ajustes);
    }

    /* ── Primer contacto ─────────────────────────────────────────────────── */

    private function primerContacto(int $companyId, string $provider, string $phone, array $ajustes, array $payload): array
    {
        // Si la empresa prefiere no molestar a los conocidos, se intenta primero
        // por teléfono; si aparece, la conversación pasa ya identificada.
        if ($ajustes['identificacion_solo_desconocidos']) {
            $cliente = $this->clientePorTelefono($companyId, $phone);

            if ($cliente) {
                $this->guardar($companyId, $provider, $phone, [
                    'estado'  => 'identificado',
                    'dni'     => $cliente->dni,
                    'user_id' => $cliente->user_id,
                    'nombre'  => trim($cliente->names . ' ' . $cliente->lastname),
                ]);

                return [
                    'accion'  => self::PASA,
                    'dni'     => $cliente->dni,
                    'user_id' => (int) $cliente->user_id,
                    'nombre'  => trim($cliente->names . ' ' . $cliente->lastname),
                ];
            }
        }

        $this->guardar($companyId, $provider, $phone, [
            'estado'    => 'preguntado',
            'intentos'  => 0,
            'retenidos' => json_encode([$payload], JSON_UNESCAPED_UNICODE),
        ]);

        $this->enviar($companyId, $provider, $phone, $ajustes['identificacion_mensaje'] ?: self::PREGUNTA_POR_DEFECTO);

        return ['accion' => self::RETIENE];
    }

    /* ── Respuesta del cliente ───────────────────────────────────────────── */

    private function respuesta(int $companyId, string $provider, string $phone, ?string $texto, array $payload, object $registro, array $ajustes): array
    {
        $retenidos = json_decode($registro->retenidos ?: '[]', true) ?: [];
        $retenidos[] = $payload;

        $lectura = IdentificacionEnTexto::extraer($texto);
        $intentos = (int) $registro->intentos + 1;

        // Se prueba cada número que parezca documento, del más probable al menos.
        foreach ($lectura['candidatos'] as $posible) {
            $cliente = $this->clientePorDni($companyId, $posible);

            if ($cliente) {
                $nombre = trim($cliente->names . ' ' . $cliente->lastname);

                $this->guardar($companyId, $provider, $phone, [
                    'estado'    => 'identificado',
                    'intentos'  => $intentos,
                    'dni'       => $cliente->dni,
                    'user_id'   => $cliente->user_id,
                    'nombre'    => $nombre,
                    'retenidos' => null,
                ]);

                $this->enviar($companyId, $provider, $phone,
                    "¡Gracias, {$cliente->names}! ✅ Ya te identifiqué.\n" .
                    "En un momento te atiende un asesor.");

                return [
                    'accion'    => self::PASA,
                    'dni'       => $cliente->dni,
                    'user_id'   => (int) $cliente->user_id,
                    'nombre'    => $nombre,
                    'retenidos' => $retenidos,
                ];
            }
        }

        // Dio un número pero no está en el sistema: pasa al agente igual.
        if ($lectura['documento']) {
            $this->guardar($companyId, $provider, $phone, [
                'estado'    => 'sin_registro',
                'intentos'  => $intentos,
                'dni'       => $lectura['documento'],
                'nombre'    => $lectura['nombre'],
                'retenidos' => null,
            ]);

            $this->enviar($companyId, $provider, $phone,
                "No encontré esa cédula en nuestro sistema. 🤔\n" .
                "Te paso con un asesor para que te ayude.");

            return [
                'accion'    => self::PASA,
                'dni'       => $lectura['documento'],
                'user_id'   => null,
                'nombre'    => $lectura['nombre'],
                'retenidos' => $retenidos,
            ];
        }

        // No mandó ningún número. Se reintenta, pero con tope.
        if ($intentos >= max(1, (int) $ajustes['identificacion_intentos'])) {
            $this->guardar($companyId, $provider, $phone, [
                'estado'    => 'sin_registro',
                'intentos'  => $intentos,
                'nombre'    => $lectura['nombre'],
                'retenidos' => null,
            ]);

            $this->enviar($companyId, $provider, $phone, 'Te paso con un asesor para que te ayude. 🙌');

            return [
                'accion'    => self::PASA,
                'dni'       => null,
                'user_id'   => null,
                'nombre'    => $lectura['nombre'],
                'retenidos' => $retenidos,
            ];
        }

        $this->guardar($companyId, $provider, $phone, [
            'estado'    => 'preguntado',
            'intentos'  => $intentos,
            'retenidos' => json_encode($retenidos, JSON_UNESCAPED_UNICODE),
        ]);

        $this->enviar($companyId, $provider, $phone, self::REINTENTO);

        return ['accion' => self::RETIENE];
    }

    /* ── Consultas ───────────────────────────────────────────────────────── */

    /** Cliente de la empresa por documento exacto (ya viene sin separadores). */
    private function clientePorDni(int $companyId, string $dni): ?object
    {
        return DB::table('user_data as ud')
            ->join('users as u', 'u.id', '=', 'ud.user_id')
            ->where('u.company_id', $companyId)
            ->where('ud.dni', $dni)
            ->first(['ud.user_id', 'ud.dni', 'ud.names', 'ud.lastname']);
    }

    /**
     * Cliente por teléfono. Se comparan los últimos 10 dígitos porque en base
     * conviven formatos con y sin indicativo (+57, 57, o pelado).
     */
    private function clientePorTelefono(int $companyId, string $phone): ?object
    {
        $corto = substr(preg_replace('/\D/', '', $phone), -10);

        if (strlen($corto) < 7) {
            return null;
        }

        return DB::table('user_data as ud')
            ->join('users as u', 'u.id', '=', 'ud.user_id')
            ->where('u.company_id', $companyId)
            ->whereRaw('RIGHT(REGEXP_REPLACE(ud.phone, "[^0-9]", ""), 10) = ?', [$corto])
            ->first(['ud.user_id', 'ud.dni', 'ud.names', 'ud.lastname']);
    }

    /* ── Utilidades ──────────────────────────────────────────────────────── */

    private function guardar(int $companyId, string $provider, string $phone, array $datos): void
    {
        DB::table(self::TABLA)->updateOrInsert(
            ['company_id' => $companyId, 'provider' => $provider, 'phone' => $phone],
            $datos + ['updated_at' => now(), 'created_at' => now()]
        );
    }

    private function enviar(int $companyId, string $provider, string $phone, string $texto): void
    {
        try {
            (new WhatsAppService($companyId, false, $provider, $this->instanceId))->mensajeInformativo($phone, $texto);
        } catch (\Throwable $e) {
            // Que no se caiga el webhook si el envío falla: el mensaje del
            // cliente igual quedó guardado y se suelta cuando corresponda.
            Log::warning('[Identificación] No se pudo enviar el mensaje', [
                'company_id' => $companyId, 'phone' => $phone, 'error' => $e->getMessage(),
            ]);
        }
    }

    /** @return array<string,mixed> */
    private function ajustes(int $companyId): array
    {
        $fila = DB::table('crm_settings')->where('company_id', $companyId)->first();

        return [
            'identificacion_enabled'           => (bool) ($fila->identificacion_enabled ?? false),
            'identificacion_solo_desconocidos' => (bool) ($fila->identificacion_solo_desconocidos ?? false),
            'identificacion_intentos'          => (int) ($fila->identificacion_intentos ?? 2),
            'identificacion_mensaje'           => $fila->identificacion_mensaje ?? null,
        ];
    }
}
