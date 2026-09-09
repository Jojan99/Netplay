<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Autentica las llamadas máquina a máquina del servicio de WhatsApp.
 *
 * Es la misma clave que ya comparten Laravel y el servicio Node para el
 * aprovisionamiento (NETPLAY_WS_MASTER_KEY / MASTER_KEY). Se compara en tiempo
 * constante para no filtrar por tiempo cuántos caracteres coinciden.
 */
class ClaveMaestraMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $esperada = (string) config('services.netplay_whatsapp.master_key', '');
        $recibida = (string) $request->header('x-master-key', '');

        if ($esperada === '' || $recibida === '' || !hash_equals($esperada, $recibida)) {
            return response()->json(['ok' => false, 'motivo' => 'clave_maestra_invalida'], 401);
        }

        return $next($request);
    }
}
