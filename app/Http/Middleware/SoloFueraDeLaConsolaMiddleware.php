<?php

namespace App\Http\Middleware;

use App\Services\Plataforma\AccesoConsola;
use Closure;
use Illuminate\Http\Request;

/**
 * En la dirección de la consola no se sirve nada del panel de empresas ni del
 * portal de clientes.
 *
 * Así admin.netvula.com es sólo la consola: ni login de empresa, ni API de
 * clientes, ni pasarelas. Lo que no es de la consola, ahí no existe.
 */
class SoloFueraDeLaConsolaMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        if (AccesoConsola::esElHost($request)) {
            abort(404);
        }

        return $next($request);
    }
}
