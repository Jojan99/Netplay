<?php

namespace App\Services;

use App\Models\WaNotificationRoute;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dispatches WhatsApp notifications to configured destinations per event type.
 *
 * Event types:
 *   payment        – pago o abono registrado (finanzas)
 *   new_user       – nuevo usuario creado
 *   ticket_support – ticket de soporte creado
 *   ticket_install – ticket de instalación creado
 */
class NotificationRouterService
{
    /**
     * Send a message to every enabled route for the given event.
     * Never throws — all errors are logged.
     */
    public static function dispatch(int $companyId, string $eventType, string $message): void
    {
        try {
            $routes = WaNotificationRoute::where('company_id', $companyId)
                ->where('event_type', $eventType)
                ->where('enabled', true)
                ->get();

            if ($routes->isEmpty()) return;

            $wa = new WhatsAppService($companyId, false, 'netplay');

            foreach ($routes as $route) {
                try {
                    $wa->mensajeInformativo($route->destination, $message);
                } catch (Throwable $e) {
                    Log::warning("[WA_ROUTE] Error enviando a {$route->destination}", [
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
     * Human-readable labels for each event type.
     */
    public static function eventLabels(): array
    {
        return [
            'payment'             => '💰 Pago / Abono recibido',
            'new_user'            => '👤 Nuevo usuario creado',
            'ticket_support'      => '🔧 Ticket de soporte creado',
            'ticket_install'      => '📦 Ticket de instalación creado',
            'ticket_status_change'=> '🔄 Cambio de estado de ticket',
            'ticket_reopen'       => '🔁 Ticket reabierto',
        ];
    }
}
