<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Rutas que sólo llama el propio servidor (el servicio de WhatsApp que corre
 * en la misma máquina), sin sesión de usuario detrás.
 *
 * Estaban abiertas a internet: cualquiera podía inyectar mensajes entrantes
 * falsos en el CRM. Se mira la dirección real de la conexión (REMOTE_ADDR) y no
 * los encabezados de proxy, que el que llama puede inventar.
 */
class SoloDesdeElServidorMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $origen = (string) $request->server('REMOTE_ADDR', '');

        $permitidas = array_filter(array_map('trim', array_merge(
            ['127.0.0.1', '::1'],
            explode(',', (string) env('IPS_DEL_SERVIDOR', '181.48.150.43'))
        )));

        if (!in_array($origen, $permitidas, true)) {
            return response()->json(['ok' => false, 'motivo' => 'origen_no_permitido'], 403);
        }

        return $next($request);
    }
}
