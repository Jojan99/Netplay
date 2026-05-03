<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use App\Constants\ApiResponseConstants;
use Tymon\JWTAuth\Facades\JWTAuth;
use Exception;

/**
 * Middleware exclusivo del portal de cliente.
 * Valida el JWT y verifica que el perfil del usuario sea 'USER' (por nombre,
 * no por ID, porque cada empresa tiene su propio rango de profile_ids).
 * Impide que admins, técnicos o contadores accedan a las rutas del portal.
 */
class ClientJwtMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();

            if (!$user) {
                return $this->unauthorized('Token inválido o usuario no encontrado');
            }

            // Validar por nombre del perfil, no por ID.
            // profiles.name = 'USER' aplica para todas las empresas (cada una tiene su propio ID).
            $profileName = DB::table('profiles')
                ->where('id', $user->profile_id)
                ->value('name');

            if (strtoupper($profileName ?? '') !== 'USER') {
                return $this->unauthorized('Acceso no permitido para este tipo de usuario');
            }

            $request->merge(['_client_user' => $user]);

        } catch (\Tymon\JWTAuth\Exceptions\TokenInvalidException $e) {
            return $this->unauthorized('Token inválido');
        } catch (\Tymon\JWTAuth\Exceptions\TokenExpiredException $e) {
            return $this->unauthorized('Sesión expirada');
        } catch (Exception $e) {
            return $this->unauthorized('Token no autorizado');
        }

        return $next($request);
    }

    private function unauthorized(string $message): Response
    {
        return response()->json([
            'message' => $message,
            'data'    => null,
            'status'  => ApiResponseConstants::ERROR,
        ], 401);
    }
}
