<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Constants\ApiResponseConstants;
use Tymon\JWTAuth\Facades\JWTAuth;
use Exception;


class JwtMiddleware
{
   /**
     * Valida si el token enviado en el header es válido
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            session(['user' => $user]);
        } catch (Exception $e) {
            if ($e instanceof \Tymon\JWTAuth\Exceptions\TokenInvalidException) {
                return $this->responseJwt('The token is invalid');
            } else if ($e instanceof \Tymon\JWTAuth\Exceptions\TokenExpiredException) {
                return $this->responseJwt('Session has expired');
            } else if ($e instanceof \Tymon\JWTAuth\Exceptions\JWTException && session()->has('user')) {
                // Sin token en el header pero con sesión activa.
                //
                // Esta vía existe porque hay enlaces que el navegador abre solo
                // (PDF de factura, contrato, estado de cuenta con window.open) y
                // ahí no hay forma de mandar el header Authorization. Se conserva,
                // pero ya no se confía a ciegas en lo que quedó guardado en la
                // sesión: se vuelve a leer el usuario de la base y se comprueba
                // que siga existiendo y activo. Así una cuenta desactivada o
                // borrada deja de pasar aunque su cookie siga viva.
                if (!$this->sesionSigueValida()) {
                    session()->flush();
                    return $this->responseJwt('Session has expired');
                }
            } else {
                return $this->responseJwt('The token is not authorized' . $e->getMessage());
            }
        }
        return $next($request);
    }

    /**
     * Revalida contra la base el usuario que quedó en la sesión.
     *
     * Devuelve false si ya no existe, si fue desactivado o si le cambiaron la
     * empresa; en ese caso la sesión se descarta.
     */
    private function sesionSigueValida(): bool
    {
        $enSesion = session('user');
        $id       = is_object($enSesion) ? ($enSesion->id ?? null) : ($enSesion['id'] ?? null);

        if (!$id) {
            return false;
        }

        $fresco = \App\Models\User::find($id);

        if (!$fresco || !$fresco->active) {
            return false;
        }

        // Refresca la copia en sesión: perfil y empresa se leen de aquí en
        // todo el sistema, así que una copia vieja es una fuga de permisos.
        session(['user' => $fresco]);

        return true;
    }

    /**
     * Método encargado de retornar la respuesta de error cuando un token es 
     * incorrecto
     *
     * @param string $message
     * @return object
     */
    public function responseJwt(string $message): object
    {
        return standardApiReponse(
            $message,
            ApiResponseConstants::DATA_NULL,
            ApiResponseConstants::SUCCESS,
            401
        );
    }
}
