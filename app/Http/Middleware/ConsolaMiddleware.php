<?php

namespace App\Http\Middleware;

use App\Constants\ApiResponseConstants;
use App\Services\Plataforma\AccesoConsola;
use Closure;
use Illuminate\Http\Request;

/**
 * La puerta de la consola de Netvula.
 *
 * Dos cierres, no uno:
 *
 *  1. la petición tiene que llegar a la dirección de la consola
 *     (admin.netvula.com). Desde el panel de una empresa estas rutas ni
 *     siquiera existen, pero por si alguna vez se registran fuera del grupo
 *     con dominio, aquí se vuelve a comprobar;
 *  2. el token tiene que ser de una sesión de la consola. Un JWT del panel no
 *     sirve: no es un token de esta tabla y se descarta sin consultar nada.
 *
 * El usuario queda en la petición como 'consola_usuario'. No se toca la
 * sesión del panel: los dos mundos no se cruzan.
 */
class ConsolaMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        if (!AccesoConsola::esElHost($request)) {
            abort(404);
        }

        $usuario = AccesoConsola::usuarioDe($request);

        if (!$usuario) {
            return standardApiReponse(
                'Su sesión de la consola venció o no es válida. Vuelva a ingresar.',
                ApiResponseConstants::DATA_NULL,
                ApiResponseConstants::ERROR,
                401
            );
        }

        $request->attributes->set('consola_usuario', $usuario);

        return $next($request);
    }
}
