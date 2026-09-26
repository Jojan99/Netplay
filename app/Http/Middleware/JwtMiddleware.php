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

            // El panel y su API son para operadores. Portal y panel emiten el
            // mismo tipo de token, así que con el suyo un cliente podía llamar
            // cualquier ruta del panel. El portal vive en /api/client con su
            // propio middleware y no pasa por aquí.
            if ($user && self::esCliente($user)) {
                return $this->soloOperadores();
            }

            // Empresa suspendida por Netvula: el token que ya tenía tampoco
            // sirve. Sólo cierra el panel del operador; el portal de sus
            // clientes vive en jwt.client y no pasa por aquí.
            if ($user && $this->empresaSuspendida($user)) {
                return $this->empresaCerrada();
            }

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

                if (self::esCliente(session('user'))) {
                    return $this->soloOperadores();
                }

                if ($this->empresaSuspendida(session('user'))) {
                    return $this->empresaCerrada();
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
    /** Perfil de cliente (USER): tiene su portal, no entra al panel. */
    public static function esCliente(mixed $user): bool
    {
        $perfilId = is_object($user) ? ($user->profile_id ?? null) : ($user['profile_id'] ?? null);

        if (!$perfilId) {
            return false;
        }

        static $nombres = [];
        $nombres[$perfilId] ??= strtoupper((string) \Illuminate\Support\Facades\DB::table('profiles')->where('id', $perfilId)->value('name'));

        return $nombres[$perfilId] === 'USER';
    }

    /**
     * ¿La empresa del usuario está suspendida por la plataforma?
     *
     * Cierra el panel del operador y nada más: el portal de sus clientes va
     * por jwt.client y no pasa por aquí, y la consola de Netvula vive en otra
     * dirección con sus propios usuarios, así que suspender una empresa no
     * deja a nadie de Netvula afuera.
     */
    private function empresaSuspendida(mixed $user): bool
    {
        static $hayColumna = null;
        $hayColumna ??= \Illuminate\Support\Facades\Schema::hasColumn('companies', 'plataforma_suspendida');

        if (!$hayColumna) {
            return false;
        }

        $companyId = is_object($user) ? ($user->company_id ?? null) : ($user['company_id'] ?? null);

        if (!$companyId) {
            return false;
        }

        return (bool) \Illuminate\Support\Facades\DB::table('companies')->where('id', $companyId)->value('plataforma_suspendida');
    }

    private function empresaCerrada()
    {
        return response()->json([
            'message' => 'El acceso de su empresa a la plataforma está suspendido. Escribinos para reactivarlo.',
            'data'    => null,
            'error'   => 1,
        ], 403);
    }

    private function soloOperadores()
    {
        return response()->json([
            'message' => 'Este acceso es para el equipo de la empresa. Los clientes ingresan por el portal.',
            'data'    => null,
            'error'   => 1,
        ], 403);
    }

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
