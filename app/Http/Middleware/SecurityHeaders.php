<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Cabeceras de seguridad para todo lo que sale de Laravel.
 *
 * Cubre la API y las páginas públicas que arma el backend (firma de contrato,
 * estado de cuenta, factura por enlace). El SPA lo sirve nginx directamente,
 * así que ese lado necesita el bloque equivalente en el vhost.
 *
 * La CSP es deliberadamente permisiva con estilos y scripts en línea porque
 * las páginas públicas los usan; lo que sí cierra son los vectores que no
 * hacen falta en ningún caso: plugins, <base> inyectado y embebido en iframes
 * de terceros.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        // Algunas respuestas (descargas por streaming) no exponen headers modificables
        if (!method_exists($response, 'header')) {
            return $response;
        }

        // Evita que el navegador "adivine" el tipo: es lo que convierte un
        // archivo subido en HTML ejecutable dentro de nuestro propio dominio.
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'geolocation=(self), microphone=(self), camera=(self), payment=()');
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');

        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        if (!$response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                "base-uri 'self'",
                "object-src 'none'",
                "frame-ancestors 'self'",
                "form-action 'self'",
                "img-src 'self' data: blob: https:",
                "media-src 'self' data: blob: https:",
                "font-src 'self' data: https:",
                "style-src 'self' 'unsafe-inline' https:",
                "script-src 'self' 'unsafe-inline' https:",
                "connect-src 'self' https: wss:",
            ]));
        }

        return $response;
    }
}
