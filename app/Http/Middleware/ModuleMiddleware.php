<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Deja pasar sólo si el perfil del usuario tiene activo alguno de los módulos
 * indicados: `->middleware('module:usuario')` o `module:finanzas,resumen`.
 *
 * Los módulos se configuran por empresa en Staff › Permisos por perfil, así que
 * esto hace valer del lado del servidor lo mismo que el menú muestra.
 */
class ModuleMiddleware
{
    public function handle(Request $request, Closure $next, string ...$modules)
    {
        $user = JWTAuth::user();

        if (!$user) {
            return response()->json(['message' => 'No autenticado.', 'data' => null, 'error' => 1], JsonResponse::HTTP_UNAUTHORIZED);
        }

        // El perfil ADMIN de la empresa siempre pasa: es quien configura los permisos.
        $profile = DB::table('profiles')->where('id', $user->profile_id)->first(['name', 'company_id']);
        if ($profile && strtoupper((string) $profile->name) === 'ADMIN') {
            return $next($request);
        }

        $allowed = DB::table('profile_modules')
            ->where('profile_id', $user->profile_id)
            ->where('active', true)
            ->pluck('module')
            ->all();

        foreach ($modules as $module) {
            if (in_array($module, $allowed, true)) {
                return $next($request);
            }
        }

        return response()->json([
            'message' => 'Tu perfil no tiene permiso para esta sección.',
            'data'    => null,
            'error'   => 1,
        ], JsonResponse::HTTP_FORBIDDEN);
    }
}
