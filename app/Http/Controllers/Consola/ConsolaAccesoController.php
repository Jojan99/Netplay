<?php

namespace App\Http\Controllers\Consola;

use App\Http\Controllers\Controller;
use App\Models\PlataformaUsuario;
use App\Services\Plataforma\AccesoConsola;
use App\Services\Plataforma\Passkeys;
use App\Services\Plataforma\SegundoFactor;
use App\Services\Plataforma\Bitacora;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Ingreso a la consola de Netvula.
 *
 * Nada que ver con el login del panel: otra tabla de usuarios, otro token y
 * otra dirección. Un usuario de empresa no tiene forma de entrar aquí.
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

        // La contraseña estuvo bien pero falta el código del authenticator.
        if (!$r['token'] && !empty($r['pase'])) {
            return response()->json([
                'message' => 'Ingrese el código de su authenticator.',
                'error'   => 0,
                'data'    => ['falta_codigo' => true, 'pase' => $r['pase']],
            ]);
        }

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

    /**
     * POST /api/consola/login/codigo  { pase, codigo }
     *
     * El segundo paso. Acepta tanto el código del authenticator como uno de
     * recuperación: quien perdió el teléfono tiene que poder entrar, y por la
     * misma puerta.
     */
    public function loginConCodigo(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'pase'   => 'required|string|max:80',
            'codigo' => 'required|string|max:20',
        ]);

        $r = SegundoFactor::comprobar($datos['pase'], $datos['codigo'], $request);

        if (!$r['usuario']) {
            return response()->json(['message' => $r['motivo'], 'data' => null, 'error' => 1], JsonResponse::HTTP_OK);
        }

        $usuario = $r['usuario'];
        $token = AccesoConsola::abrirSesion($usuario, $request);

        Bitacora::anotarComo($usuario, 'consola.ingreso', null, [
            'ip' => $request->ip(), 'con_recuperacion' => $r['era_recuperacion'],
        ]);

        $respuesta = response()->json([
            'message' => 'Adentro.',
            'error'   => 0,
            'data'    => [
                'token'      => $token,
                'expira_en'  => (int) config('plataforma.consola_minutos', 480) * 60,
                'usuario'    => ['id' => $usuario->id, 'nombre' => $usuario->nombre, 'email' => $usuario->email],
                'plataforma' => config('plataforma.nombre', 'Netvula'),
                // Para que la pantalla insista en generar códigos nuevos: sin
                // ninguno, perder el teléfono es quedarse afuera.
                'uso_recuperacion'   => $r['era_recuperacion'],
                'recuperacion_queda' => SegundoFactor::recuperacionesQueQuedan($usuario),
            ],
        ]);

        // El equipo queda recordado 30 días para no pedir el código cada vez.
        if ($galleta = SegundoFactor::galleta()) {
            $respuesta->withCookie($galleta);
        }

        return $respuesta;
    }

    // ── Passkeys ────────────────────────────────────────────────────────

    /** POST /api/consola/login/passkey/opciones — el desafío, sin pedir correo. */
    public function opcionesDePasskey(): JsonResponse
    {
        return response()->json(['message' => 'OK', 'error' => 0, 'data' => Passkeys::opcionesParaEntrar()]);
    }

    /** POST /api/consola/login/passkey  { pase, respuesta } */
    public function loginConPasskey(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'pase'      => 'required|string|max:80',
            'respuesta' => 'required|string|max:20000',
        ]);

        $r = Passkeys::entrar($datos['pase'], $datos['respuesta'], $request);

        if (!$r['usuario']) {
            Log::warning('[Consola] passkey rechazada', ['ip' => $request->ip()]);

            return response()->json(['message' => $r['motivo'], 'data' => null, 'error' => 1], JsonResponse::HTTP_OK);
        }

        $usuario = $r['usuario'];
        $token = AccesoConsola::abrirSesion($usuario, $request);

        Bitacora::anotarComo($usuario, 'consola.ingreso', null, ['ip' => $request->ip(), 'con' => 'passkey']);

        return response()->json([
            'message' => 'Adentro.',
            'error'   => 0,
            'data'    => [
                'token'      => $token,
                'expira_en'  => (int) config('plataforma.consola_minutos', 480) * 60,
                'usuario'    => ['id' => $usuario->id, 'nombre' => $usuario->nombre, 'email' => $usuario->email],
                'plataforma' => config('plataforma.nombre', 'Netvula'),
            ],
        ]);
    }

    /** GET /api/consola/passkeys — las que tiene registradas. */
    public function verPasskeys(Request $request): JsonResponse
    {
        $u = $request->attributes->get('consola_usuario');

        return response()->json(['message' => 'OK', 'error' => 0, 'data' => Passkeys::deUsuario($u)]);
    }

    /** POST /api/consola/passkeys/opciones — para registrar una nueva. */
    public function opcionesDeAltaDePasskey(Request $request): JsonResponse
    {
        $u = $request->attributes->get('consola_usuario');

        return response()->json(['message' => 'OK', 'error' => 0, 'data' => Passkeys::opcionesParaRegistrar($u)]);
    }

    /** POST /api/consola/passkeys  { respuesta, nombre? } */
    public function guardarPasskey(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'respuesta' => 'required|string|max:20000',
            'nombre'    => 'nullable|string|max:110',
        ]);

        $u = $request->attributes->get('consola_usuario');
        $r = Passkeys::guardar($u, $datos['respuesta'], $datos['nombre'] ?? null);

        if (!$r['ok']) {
            return response()->json(['message' => $r['motivo'], 'data' => null, 'error' => 1], JsonResponse::HTTP_OK);
        }

        Bitacora::anotarComo($u, 'consola.passkey.alta', null, ['ip' => $request->ip()]);

        return response()->json(['message' => 'Passkey guardada.', 'error' => 0, 'data' => Passkeys::deUsuario($u)]);
    }

    /** DELETE /api/consola/passkeys/{id} */
    public function borrarPasskey(Request $request, int $id): JsonResponse
    {
        $u = $request->attributes->get('consola_usuario');

        if (!Passkeys::borrar($u, $id)) {
            return response()->json(['message' => 'Esa passkey no es suya o ya no está.', 'data' => null, 'error' => 1], JsonResponse::HTTP_OK);
        }

        Bitacora::anotarComo($u, 'consola.passkey.baja', null, ['ip' => $request->ip()]);

        return response()->json(['message' => 'Passkey eliminada.', 'error' => 0, 'data' => Passkeys::deUsuario($u)]);
    }

    /** GET /api/consola/2fa — cómo está hoy. */
    public function verSegundoFactor(Request $request): JsonResponse
    {
        $u = $request->attributes->get('consola_usuario');

        return response()->json(['message' => 'OK', 'error' => 0, 'data' => [
            'activo'             => $u->tieneSegundoFactor(),
            'activo_en'          => $u->totp_activo_en,
            'recuperacion_queda' => SegundoFactor::recuperacionesQueQuedan($u),
        ]]);
    }

    /**
     * POST /api/consola/2fa/preparar — el secreto y el QR.
     *
     * No queda activo todavía: si se activara antes de confirmar y la app
     * quedó mal configurada, el usuario se queda afuera de su propia consola.
     */
    public function prepararSegundoFactor(Request $request): JsonResponse
    {
        $u = $request->attributes->get('consola_usuario');

        return response()->json([
            'message' => 'Escanee el código con su authenticator.',
            'error'   => 0,
            'data'    => SegundoFactor::preparar($u),
        ]);
    }

    /** POST /api/consola/2fa/confirmar  { codigo } */
    public function confirmarSegundoFactor(Request $request): JsonResponse
    {
        $datos = $request->validate(['codigo' => 'required|string|max:10']);
        $u = $request->attributes->get('consola_usuario');

        $r = SegundoFactor::confirmar($u, $datos['codigo']);

        if (!$r['ok']) {
            return response()->json(['message' => $r['motivo'], 'data' => null, 'error' => 1], JsonResponse::HTTP_OK);
        }

        Bitacora::anotarComo($u, 'consola.2fa.activado', null, ['ip' => $request->ip()]);

        return response()->json([
            'message' => 'Listo. Guarde estos códigos: son la única forma de entrar si pierde el teléfono.',
            'error'   => 0,
            'data'    => ['codigos' => $r['codigos']],
        ]);
    }

    /**
     * POST /api/consola/2fa/quitar  { password }
     *
     * Pide la contraseña de nuevo a propósito: si alguien se encuentra una
     * sesión abierta, no puede desarmar la protección sin saber la clave.
     */
    public function quitarSegundoFactor(Request $request): JsonResponse
    {
        $datos = $request->validate(['password' => 'required|string|max:200']);
        $u = $request->attributes->get('consola_usuario');

        if (!\Illuminate\Support\Facades\Hash::check($datos['password'], (string) $u->password)) {
            return response()->json(['message' => 'Esa no es su contraseña.', 'data' => null, 'error' => 1], JsonResponse::HTTP_OK);
        }

        SegundoFactor::quitar($u);
        Bitacora::anotarComo($u, 'consola.2fa.desactivado', null, ['ip' => $request->ip()]);

        return response()->json(['message' => 'Authenticator desactivado.', 'error' => 0, 'data' => null]);
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
            'message' => 'Contraseña cambiada. Vuelva a ingresar.',
            'data'    => null,
            'error'   => 0,
        ]);
    }
}
