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
            } else {
                return $this->responseJwt('The token is not authorized' . $e->getMessage());
            }
        }
        return $next($request);
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
