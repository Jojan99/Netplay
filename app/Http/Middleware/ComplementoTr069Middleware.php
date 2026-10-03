<?php

namespace App\Http\Middleware;

use App\Services\Plataforma\ComplementoTr069;
use Closure;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Cierra lo que pasa por el ACS a la empresa que no tiene el complemento.
 *
 * En el panel responde 403 con un código, para que la pantalla explique que
 * es un complemento en vez de mostrar un error suelto. En el portal del
 * cliente ('portal') responde como una consulta sin resultado: el suscriptor
 * no tiene por qué enterarse de qué contrató su proveedor.
 */
class ComplementoTr069Middleware
{
    public function handle(Request $request, Closure $next, string $modo = 'panel')
    {
        $user = JWTAuth::user();
        $companyId = (int) ($user->company_id ?? 0);

        if (!$companyId || ComplementoTr069::permitido($companyId)) {
            return $next($request);
        }

        if ($modo === 'portal') {
            return response()->json([
                'message' => 'Su proveedor no tiene activa la gestión remota del equipo.',
                'data'    => null,
                'error'   => 1,
            ], 200);
        }

        return response()->json([
            'message' => ComplementoTr069::mensaje(),
            'data'    => ['codigo' => ComplementoTr069::CODIGO, 'complemento' => 'tr069', 'precio' => ComplementoTr069::precio($companyId)],
            'error'   => 1,
        ], 403);
    }
}
