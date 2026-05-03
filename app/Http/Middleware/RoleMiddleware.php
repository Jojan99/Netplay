<?php

namespace App\Http\Middleware;

use App\Constants\ApiResponseConstants;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        $profileName = strtolower(
            DB::table('profiles')->where('id', $profileId)->value('name') ?? ''
        );

        if (!in_array($profileName, $roles)) {
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
