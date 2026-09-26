<?php

namespace App\Services;

use App\Models\WaNotificationRoute;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Manda por WhatsApp los avisos internos de la empresa al destino que ella
 * misma eligió en el panel (WhatsApp → Avisos y destinos).
 *
 * Cada aviso es un "evento" con su clave (ticket_support, payment…) y cada
 * empresa decide a qué grupo o número va, con varios destinos si quiere. Las
 * alertas de la red se configuran aquí también (alerta_red, alerta_resumen)
 * pero las manda AvisosAlGrupo, que necesita saber si el envío salió para no
 * repetir una alerta ya avisada.
 */
class NotificationRouterService
{
    /** Eventos de red: se listan y se configuran aquí, pero los manda AvisosAlGrupo. */
    public const EVENTOS_DE_RED = ['alerta_red', 'alerta_resumen'];

    /**
     * Manda el mensaje a cada destino activo del evento.
     * Nunca lanza: si WhatsApp falla, queda en el log y el flujo sigue.
     */
    public static function dispatch(int $companyId, string $eventType, string $message): void
    {
        try {
            $destinos = self::destinos($companyId, $eventType);

            if (!$destinos) return;

            $wa = new WhatsAppService($companyId, false, 'netplay');

            foreach (array_keys($destinos) as $destino) {
                try {
                    $wa->mensajeInformativo($destino, $message);
                } catch (Throwable $e) {
                    Log::warning("[WA_ROUTE] Error enviando a {$destino}", [
                        'event'   => $eventType,
                        'company' => $companyId,
                        'error'   => $e->getMessage(),
                    ]);
                }
            }
        } catch (Throwable $e) {
            Log::warning("[WA_ROUTE] Error general", [
                'event'   => $eventType,
                'company' => $companyId,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Destinos activos de un evento: [destino => etiqueta].
     *
     * Por destino y no por fila: si la empresa dejó dos veces el mismo grupo,
     * el aviso sale una sola vez.
     *
     * @return array<string,string>
     */
    public static function destinos(int $companyId, string $eventType): array
    {
        if (!Schema::hasTable('wa_notification_routes')) {
            return [];
        }

        $destinos = [];

        $rutas = WaNotificationRoute::where('company_id', $companyId)
            ->where('event_type', $eventType)
            ->where('enabled', true)
            ->orderBy('id')
            ->get();

        foreach ($rutas as $ruta) {
            $destino = trim((string) $ruta->destination);

            if ($destino !== '' && !isset($destinos[$destino])) {
                $destinos[$destino] = (string) (self::textoLimpio($ruta->label) ?: $destino);
            }
        }

        return $destinos;
    }

    /**
     * Nombre corto de cada evento. Sirve para el panel y para validar qué
     * claves se aceptan al crear una ruta.
     */
    public static function eventLabels(): array
    {
        $etiquetas = [];

        foreach (self::catalogo() as $evento) {
            $etiquetas[$evento['clave']] = $evento['titulo'];
        }

        return $etiquetas;
    }

    /**
     * Todo lo que la plataforma puede avisar, agrupado como lo entiende el
     * dueño del ISP. El panel dibuja esta lista tal cual: si aquí se agrega un
     * evento, aparece solo en la pantalla.
     *
     * @return list<array{clave:string, seccion:string, titulo:string, icono:string, cuando:string, solo_grupo:bool}>
     */
    public static function catalogo(): array
    {
        return [
            // ── Tickets ──────────────────────────────────────────────────────
            [
                'clave'      => 'ticket_support',
                'seccion'    => 'Tickets',
                'titulo'     => 'Ticket de soporte creado',
                'icono'      => 'ticket',
                'cuando'     => 'Apenas se abre un ticket de soporte, venga del panel, del CRM o del portal del cliente.',
                'solo_grupo' => false,
            ],
            [
                'clave'      => 'ticket_install',
                'seccion'    => 'Tickets',
                'titulo'     => 'Ticket de instalación creado',
                'icono'      => 'caja',
                'cuando'     => 'Igual que el anterior, pero cuando el tipo de servicio del ticket es una instalación.',
                'solo_grupo' => false,
            ],
            [
                'clave'      => 'ticket_status_change',
                'seccion'    => 'Tickets',
                'titulo'     => 'El técnico inicia o cierra un ticket',
                'icono'      => 'estado',
                'cuando'     => 'Cuando el técnico marca el ticket en curso y cuando lo da por finalizado, con la hora.',
                'solo_grupo' => false,
            ],
            [
                'clave'      => 'ticket_reopen',
                'seccion'    => 'Tickets',
                'titulo'     => 'Ticket reabierto',
                'icono'      => 'reabrir',
                'cuando'     => 'Cuando se vuelve a abrir un ticket ya cerrado, con el motivo de la reapertura.',
                'solo_grupo' => false,
            ],

            // ── Clientes ─────────────────────────────────────────────────────
            [
                'clave'      => 'new_user',
                'seccion'    => 'Clientes',
                'titulo'     => 'Cliente nuevo dado de alta',
                'icono'      => 'cliente',
                'cuando'     => 'Al crear un cliente en el panel, con cédula, teléfono, dirección y datos de conexión.',
                'solo_grupo' => false,
            ],
            [
                'clave'      => 'instalacion_creada',
                'seccion'    => 'Clientes',
                'titulo'     => 'Instalación agendada',
                'icono'      => 'agenda',
                'cuando'     => 'Al agendar una orden en el módulo Instalaciones, con fecha, dirección, plan y técnicos.',
                'solo_grupo' => false,
            ],

            // ── Pagos ────────────────────────────────────────────────────────
            [
                'clave'      => 'payment',
                'seccion'    => 'Pagos',
                'titulo'     => 'Pago o abono registrado',
                'icono'      => 'pago',
                'cuando'     => 'Cuando en Cartera se registra el pago completo o un abono de una factura.',
                'solo_grupo' => false,
            ],
            [
                'clave'      => 'comprobante_pago',
                'seccion'    => 'Pagos',
                'titulo'     => 'Comprobante recibido por WhatsApp',
                'icono'      => 'comprobante',
                'cuando'     => 'Cuando un cliente manda su soporte de pago al WhatsApp de la empresa y queda esperando revisión.',
                'solo_grupo' => false,
            ],
            [
                'clave'      => 'cobranza',
                'seccion'    => 'Pagos',
                'titulo'     => 'Cobranza: un cliente necesita una persona',
                'icono'      => 'pago',
                'cuando'     => 'Cuando el asistente de cobranza pasa una conversación al equipo: el cliente lo pidió, está molesto, dice que ya pagó o pide algo fuera de los límites.',
                'solo_grupo' => false,
            ],

            // ── Red ──────────────────────────────────────────────────────────
            [
                'clave'      => 'alerta_red',
                'seccion'    => 'Red',
                'titulo'     => 'Alertas críticas de la red',
                'icono'      => 'alerta',
                'cuando'     => 'Cada 15 minutos: puerto PON caído, OLT o túnel sin respuesta, cliente caído por fibra y señal crítica. También avisa cuando se resuelven.',
                'solo_grupo' => true,
            ],
            [
                'clave'      => 'alerta_resumen',
                'seccion'    => 'Red',
                'titulo'     => 'Resumen de la mañana',
                'icono'      => 'amanecer',
                'cuando'     => 'Todos los días a las 07:30, lo que sigue abierto en la red antes de que los técnicos salgan a la calle.',
                'solo_grupo' => true,
            ],
        ];
    }

    /**
     * Arregla las etiquetas mal codificadas: "InstalaciÃƒÂ³nes" es
     * "Instalaciónes" leído como latin1 dos veces. Se deshace mientras el
     * resultado siga siendo UTF-8 válido; una etiqueta sana no se toca.
     */
    public static function textoLimpio(?string $texto): ?string
    {
        if ($texto === null || $texto === '') {
            return $texto;
        }

        for ($i = 0; $i < 3; $i++) {
            if (!preg_match('/\x{00C2}|\x{00C3}|\x{0192}/u', $texto)) break;

            $vuelta = @mb_convert_encoding($texto, 'Windows-1252', 'UTF-8');

            if (!is_string($vuelta) || $vuelta === '' || !mb_check_encoding($vuelta, 'UTF-8') || str_contains($vuelta, '?')) {
                break;
            }

            $texto = $vuelta;
        }

        return $texto;
    }
}
