<?php

namespace App\Http\Middleware;

use App\Constants\ApiResponseConstants;
use App\Constants\ProfileConstants;
use Closure;
use Illuminate\Http\Request;

class RoleMiddleware
{
    /**
     * Valida que el usuario autenticado tenga uno de los roles permitidos.
     * Uso en rutas: ->middleware('role:admin,contador')
     *
     * @param  Request  $request
     * @param  Closure  $next
     * @param  string   ...$roles  Nombres de roles permitidos (admin, tecnico, contador, user)
     * @return mixed
     */
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $profileId = getSessionUserProfileId();

        $allowedIds = array_map(
            fn($role) => ProfileConstants::ROLE_MAP[$role] ?? 0,
            $roles
        );

        if (!in_array($profileId, $allowedIds)) {
            return standardApiReponse(
                'No tienes permisos para realizar esta acción',
                ApiResponseConstants::DATA_NULL,
                ApiResponseConstants::ERROR,
                403
            );
        }

        return $next($request);
    }
}
