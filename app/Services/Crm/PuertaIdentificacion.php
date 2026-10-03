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

    /** Con esta cantidad de dígitos o menos, el mensaje no se toma como un intento de dar la cédula. */
    private const DIGITOS_PARA_AVISAR = 5;

    /**
     * Segundos después de la pregunta en los que un mensaje sin números no cuenta como intento.
     * La gente escribe «buenas», «me regala el Nequi», «por favor» en tres mensajes seguidos:
     * el segundo y el tercero llegan antes de que alcance a leer la pregunta, y contarlos
     * agotaba los intentos y cerraba la identificación antes de que respondiera.
     */
    private const SEGUNDOS_DE_RAFAGA = 90;

    /** La línea de WhatsApp Web por la que entró el mensaje que se está evaluando. */
    private ?string $instanceId = null;

    /** Con esto la puerta decide igual, pero no le escribe al cliente. */
    private bool $callada = false;

    public const PREGUNTA_POR_DEFECTO =
        "¡Hola! 👋 Para atenderte y ver su cuenta necesito identificarte.\n\n" .
        "Responda este mensaje con su *número de cédula* y su *nombre*.\n" .
        "Por ejemplo: 1234567 Juan Pérez";

    private const REINTENTO =
        "No encontré un número de cédula en su mensaje. 🙏\n" .
        "Escribime solo el *número*, por ejemplo: 1234567";

    /**
     * Decide qué hacer con un mensaje entrante.
     *
     * @param  array  $payload  El webhook completo, para poder reprocesarlo al soltarlo.
     * @return array{accion: string, dni?: ?string, user_id?: ?int, nombre?: ?string, retenidos?: array}
     */
    public function evaluar(int $companyId, string $provider, string $phone, ?string $texto, array $payload, ?string $instanceId = null, ?string $tipo = null, bool $delFlujoDelBot = false): array
    {
        // La empresa puede tener varias líneas: se pregunta y se confirma por la
        // MISMA por la que escribió el cliente, no por la principal.
        $this->instanceId = $instanceId;

        // Lo que el cliente le contestó al bot de la línea (la cédula del titular de un
        // comprobante): la puerta la aprovecha para identificarlo, pero no dice nada, porque
        // el bot ya le está respondiendo. Dos voces preguntando lo mismo confunden.
        $this->callada = $delFlujoDelBot;

        $ajustes = $this->ajustes($companyId);

        if (!$ajustes['identificacion_enabled']) {
            return ['accion' => self::PASA];
        }

        $registro = DB::table(self::TABLA)
            ->where('company_id', $companyId)
            ->where('provider', $provider)
            ->where('phone', $phone)
            ->first();

        // Quedó sin identificar (no dio la cédula a tiempo, o la que dio no existía) y ahora
        // manda una que sí es de un cliente: se le reconoce, aunque llegue tarde.
        if ($registro && $registro->estado === 'sin_registro' && !$registro->user_id) {
            $tardia = $this->cedulaTardia($companyId, $provider, $phone, $texto);

            if ($tardia) {
                return $tardia;
            }
        }

        // Ya resuelto antes: no se vuelve a molestar a esta persona.
        if ($registro && in_array($registro->estado, ['identificado', 'sin_registro'], true)) {
            return [
                'accion'  => self::PASA,
                'dni'     => $registro->dni,
                'user_id' => $registro->user_id ? (int) $registro->user_id : null,
                'nombre'  => $registro->nombre,
            ];
        }

        // Primer contacto con una foto o un documento: casi siempre es un comprobante, y de
        // eso se encarga el bot de la línea, que pregunta la cédula del titular. Si además la
        // puerta preguntaba la suya, al cliente le llegaban dos pedidos de cédula seguidos.
        // Pasa sin identificar; si era otra cosa, se le pregunta con su próximo mensaje.
        if (!$registro && in_array($tipo, ['image', 'document'], true)) {
            return ['accion' => self::PASA];
        }

        // Primera vez que se sabe de esta persona y es contestándole al bot: si la cédula que
        // dio es de un cliente queda identificada; si no, no se abre ninguna pregunta.
        if (!$registro && $delFlujoDelBot) {
            return $this->cedulaTardia($companyId, $provider, $phone, $texto) ?? ['accion' => self::PASA];
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

    /** La cédula llega cuando la puerta ya se había cerrado sin identificar a la persona. */
    private function cedulaTardia(int $companyId, string $provider, string $phone, ?string $texto): ?array
    {
        if (preg_match_all('/\d/', (string) $texto) <= self::DIGITOS_PARA_AVISAR) {
            return null;
        }

        foreach (IdentificacionEnTexto::extraer($texto)['candidatos'] as $posible) {
            $cliente = $this->clientePorDni($companyId, $posible);

            if (!$cliente) {
                continue;
            }

            $nombre = trim($cliente->names . ' ' . $cliente->lastname);

            $this->guardar($companyId, $provider, $phone, [
                'estado'  => 'identificado',
                'dni'     => $cliente->dni,
                'user_id' => $cliente->user_id,
                'nombre'  => $nombre,
            ]);

            $this->enviar($companyId, $provider, $phone, "¡Gracias, {$cliente->names}! ✅ Ya le identifiqué.");

            return ['accion' => self::PASA, 'dni' => $cliente->dni, 'user_id' => (int) $cliente->user_id, 'nombre' => $nombre];
        }

        return null;
    }

    /* ── Respuesta del cliente ───────────────────────────────────────────── */

    private function respuesta(int $companyId, string $provider, string $phone, ?string $texto, array $payload, object $registro, array $ajustes): array
    {
        $retenidos = json_decode($registro->retenidos ?: '[]', true) ?: [];
        $retenidos[] = $payload;

        $lectura = IdentificacionEnTexto::extraer($texto);
        $intentoDarla = preg_match_all('/\d/', (string) $texto) > self::DIGITOS_PARA_AVISAR;

        // Lo que llega pegado a la pregunta, sin números, es el resto del saludo: se retiene
        // pero no gasta un intento.
        $enRafaga = !$intentoDarla && now()->diffInSeconds($registro->created_at) < self::SEGUNDOS_DE_RAFAGA;
        $intentos = (int) $registro->intentos + ($enRafaga ? 0 : 1);

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
                    "¡Gracias, {$cliente->names}! ✅ Ya le identifiqué.\n" .
                    "En un momento le atiende un asesor.");

                return [
                    'accion'    => self::PASA,
                    'dni'       => $cliente->dni,
                    'user_id'   => (int) $cliente->user_id,
                    'nombre'    => $nombre,
                    'retenidos' => $retenidos,
                ];
            }
        }

        // Sólo se le contesta «no encontré la cédula» a quien de verdad intentó darla:
        // un mensaje con más de cinco dígitos. La línea también se usa para otras
        // cosas, y corregirle la cédula a quien escribió «buenas» o «ya voy» molesta.
        // Dio un número pero no está en el sistema: pasa al agente igual.
        if ($lectura['documento'] && $intentoDarla) {
            $this->guardar($companyId, $provider, $phone, [
                'estado'    => 'sin_registro',
                'intentos'  => $intentos,
                'dni'       => $lectura['documento'],
                'nombre'    => $lectura['nombre'],
                'retenidos' => null,
            ]);

            $this->enviar($companyId, $provider, $phone,
                "No encontré esa cédula en nuestro sistema. 🤔\n" .
                "Le paso con un asesor para que le ayude.");

            return [
                'accion'    => self::PASA,
                'dni'       => $lectura['documento'],
                'user_id'   => null,
                'nombre'    => $lectura['nombre'],
                'retenidos' => $retenidos,
            ];
        }

        // No mandó ningún número. Se reintenta, pero con tope.
        if (!$enRafaga && $intentos >= max(1, (int) $ajustes['identificacion_intentos'])) {
            $this->guardar($companyId, $provider, $phone, [
                'estado'    => 'sin_registro',
                'intentos'  => $intentos,
                'nombre'    => $lectura['nombre'],
                'retenidos' => null,
            ]);

            $this->enviar($companyId, $provider, $phone, 'Le paso con un asesor para que le ayude. 🙌');

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

        // Sin números en el mensaje no se insiste: queda retenido y cuenta como intento,
        // así que al llegar al tope pasa al asesor igual.
        if ($intentoDarla) {
            $this->enviar($companyId, $provider, $phone, self::REINTENTO);
        }

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
        $llave = ['company_id' => $companyId, 'provider' => $provider, 'phone' => $phone];

        // created_at es la hora de la pregunta: no se pisa al actualizar.
        if (DB::table(self::TABLA)->where($llave)->exists()) {
            DB::table(self::TABLA)->where($llave)->update($datos + ['updated_at' => now()]);

            return;
        }

        DB::table(self::TABLA)->insert($llave + $datos + ['updated_at' => now(), 'created_at' => now()]);
    }

    protected function enviar(int $companyId, string $provider, string $phone, string $texto): void
    {
        if ($this->callada) {
            return;
        }

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
