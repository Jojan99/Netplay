<?php

namespace App\Http\Controllers\Consola;

use App\Http\Controllers\Controller;
use App\Models\PlataformaUsuario;
use App\Services\Plataforma\AccesoConsola;
use App\Services\Plataforma\Bitacora;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Ingreso a la consola de Netvula.
 *
 * Nada que ver con el login del panel: otra tabla de usuarios, otro token y
 * otra dirección. Un usuario de empresa no tiene forma de entrar acá.
 */
class ConsolaAccesoController extends Controller
{
    /** POST /api/consola/login  { email, password } */
    public function login(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'email'    => 'required|string|max:191',
            'password' => 'required|string|max:200',
        ]);

        $r = AccesoConsola::entrar($datos['email'], $datos['password'], $request);

        if (!$r['token']) {
            Log::warning('[Consola] intento de ingreso fallido', [
                'email' => $datos['email'], 'ip' => $request->ip(),
            ]);

            return response()->json(['message' => $r['motivo'], 'data' => null, 'error' => 1], JsonResponse::HTTP_OK);
        }

        Bitacora::anotarComo($r['usuario'], 'consola.ingreso', null, ['ip' => $request->ip()]);

        return response()->json([
            'message' => 'Adentro.',
            'error'   => 0,
            'data'    => [
                'token'      => $r['token'],
                'expira_en'  => (int) config('plataforma.consola_minutos', 480) * 60,
                'usuario'    => [
                    'id'     => $r['usuario']->id,
                    'nombre' => $r['usuario']->nombre,
                    'email'  => $r['usuario']->email,
                ],
                'plataforma' => config('plataforma.nombre', 'Netvula'),
            ],
        ]);
    }

    /** GET /api/consola/yo */
    public function yo(Request $request): JsonResponse
    {
        $u = $request->attributes->get('consola_usuario');

        return response()->json(['message' => 'OK', 'error' => 0, 'data' => [
            'id'             => $u->id,
            'nombre'         => $u->nombre,
            'email'          => $u->email,
            'ultimo_ingreso' => $u->ultimo_ingreso,
            'plataforma'     => config('plataforma.nombre', 'Netvula'),
        ]]);
    }

    /** POST /api/consola/logout */
    public function logout(Request $request): JsonResponse
    {
        AccesoConsola::salir($request);

        return response()->json(['message' => 'Sesión cerrada.', 'data' => null, 'error' => 0]);
    }

    /**
     * PUT /api/consola/clave  { actual, nueva }
     *
     * Al cambiarla se cierran todas las sesiones, también la de quien la
     * cambió: si la cambia porque se la vieron, no sirve de nada dejar viva
     * la sesión del otro.
     */
    public function cambiarClave(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'actual' => 'required|string',
            'nueva'  => 'required|string|min:10|max:200',
        ]);

        /** @var PlataformaUsuario $u */
        $u = $request->attributes->get('consola_usuario');

        if (!Hash::check($datos['actual'], $u->password)) {
            return response()->json(['message' => 'La contraseña actual no es esa.', 'data' => null, 'error' => 1], JsonResponse::HTTP_OK);
        }

        $u->forceFill(['password' => Hash::make($datos['nueva'])])->save();
        AccesoConsola::cerrarTodas((int) $u->id);

        Bitacora::anotarComo($u, 'consola.clave_cambiada');

        return response()->json([
            'message' => 'Contraseña cambiada. Volvé a ingresar.',
            'data'    => null,
            'error'   => 0,
        ]);
    }
}
