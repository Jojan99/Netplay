<?php

namespace App\Services\Plataforma;

use App\Models\PlataformaUsuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * El ingreso a la consola de Netvula, aparte de todo lo demás.
 *
 * El token NO es un JWT: es una cadena al azar que se guarda hasheada en
 * `plataforma_sesiones`. Es a propósito. Así:
 *
 *  - un token del panel (un JWT) no puede valer aquí: no está en la tabla;
 *  - un token de la consola no vale en el panel: no es un JWT y no pasa la
 *    verificación de firma;
 *  - una sesión se puede cortar desde la base, cosa que con un JWT no.
 */
class AccesoConsola
{
    /** El nombre del header y del campo donde viaja el token. */
    public const HEADER = 'Authorization';

    public static function hayTablas(): bool
    {
        static $hay = null;

        return $hay ??= Schema::hasTable('plataforma_usuarios') && Schema::hasTable('plataforma_sesiones');
    }

    /** ¿Esta petición llega a la dirección de la consola? */
    public static function esElHost(Request $request): bool
    {
        $host = self::host();

        return $host !== '' && strtolower(preg_replace('/:\d+$/', '', $request->getHost())) === $host;
    }

    /** La dirección de la consola, o '' si está apagada. */
    public static function host(): string
    {
        return strtolower(trim((string) config('plataforma.consola_host', '')));
    }

    /**
     * Revisa usuario y clave.
     *
     * Devuelve el token cuando entra, o null y el motivo cuando no. El mensaje
     * no distingue "no existe" de "clave incorrecta": no hay razón para
     * ayudar a adivinar quién trabaja en Netvula.
     *
     * Con el authenticator activo devuelve `pase` en vez de `token`: falta el
     * segundo paso.
     *
     * @return array{token:?string, usuario:?PlataformaUsuario, motivo:?string, pase?:string}
     */
    public static function entrar(string $email, string $clave, Request $request): array
    {
        if (!self::hayTablas()) {
            return ['token' => null, 'usuario' => null, 'motivo' => 'La consola todavía no está instalada.'];
        }

        $usuario = PlataformaUsuario::whereRaw('LOWER(email) = ?', [strtolower(trim($email))])->first();

        // Se comprueba el hash igual cuando el usuario no existe, para que
        // tardar menos no delate que ese correo no está.
        $hash = $usuario?->password ?: '$2y$12$' . str_repeat('x', 53);
        $bien = Hash::check($clave, $hash);

        if (!$usuario || !$bien) {
            return ['token' => null, 'usuario' => null, 'motivo' => 'Correo o contraseña incorrectos.'];
        }

        if (!$usuario->activo) {
            return ['token' => null, 'usuario' => null, 'motivo' => 'Esa cuenta está desactivada.'];
        }

        // Con authenticator, la contraseña ya no entrega la sesión: entrega un
        // pase corto que sólo sirve para presentar el código. Así una
        // contraseña robada no alcanza para entrar.
        if (SegundoFactor::haceFalta($usuario, $request)) {
            return [
                'token'   => null,
                'usuario' => $usuario,
                'motivo'  => null,
                'pase'    => SegundoFactor::abrirPase($usuario),
            ];
        }

        return ['token' => self::abrirSesion($usuario, $request), 'usuario' => $usuario, 'motivo' => null];
    }

    /** Crea la sesión y devuelve el token en claro (la única vez que se ve). */
    public static function abrirSesion(PlataformaUsuario $usuario, Request $request): string
    {
        self::limpiarVencidas();

        $token = Str::random(64);

        DB::table('plataforma_sesiones')->insert([
            'usuario_id' => $usuario->id,
            'token_hash' => hash('sha256', $token),
            'expira_en'  => now()->addMinutes((int) config('plataforma.consola_minutos', 480)),
            'ip'         => $request->ip(),
            'agente'     => Str::limit((string) $request->userAgent(), 250, ''),
            'created_at' => now(),
        ]);

        $usuario->forceFill(['ultimo_ingreso' => now()])->save();

        return $token;
    }

    /**
     * El usuario de la consola detrás del token de esta petición, o null.
     *
     * Renueva el vencimiento mientras se usa: así una tarde de trabajo no se
     * corta a la mitad, pero una sesión olvidada igual vence.
     */
    public static function usuarioDe(Request $request): ?PlataformaUsuario
    {
        if (!self::hayTablas()) {
            return null;
        }

        $token = self::tokenDe($request);

        if ($token === null) {
            return null;
        }

        $sesion = DB::table('plataforma_sesiones')->where('token_hash', hash('sha256', $token))->first();

        if (!$sesion || $sesion->expira_en < now()->toDateTimeString()) {
            return null;
        }

        $usuario = PlataformaUsuario::find($sesion->usuario_id);

        if (!$usuario || !$usuario->activo) {
            return null;
        }

        DB::table('plataforma_sesiones')->where('id', $sesion->id)->update([
            'ultimo_uso_en' => now(),
            'expira_en'     => now()->addMinutes((int) config('plataforma.consola_minutos', 480)),
        ]);

        return $usuario;
    }

    /** Cierra la sesión de esta petición. */
    public static function salir(Request $request): void
    {
        $token = self::tokenDe($request);

        if ($token !== null && self::hayTablas()) {
            DB::table('plataforma_sesiones')->where('token_hash', hash('sha256', $token))->delete();
        }
    }

    /** Cierra todas las sesiones de un usuario (al cambiarle la clave). */
    public static function cerrarTodas(int $usuarioId): void
    {
        if (self::hayTablas()) {
            DB::table('plataforma_sesiones')->where('usuario_id', $usuarioId)->delete();
        }
    }

    /** El token del header Authorization: Bearer … */
    private static function tokenDe(Request $request): ?string
    {
        $cabecera = (string) $request->header(self::HEADER, '');

        if (!preg_match('/^Bearer\s+(\S+)$/i', trim($cabecera), $m)) {
            return null;
        }

        // Un JWT del panel trae dos puntos; aquí no sirve, y se descarta antes
        // de tocar la base.
        return str_contains($m[1], '.') ? null : $m[1];
    }

    /** Las sesiones vencidas no hacen falta para nada. */
    private static function limpiarVencidas(): void
    {
        DB::table('plataforma_sesiones')->where('expira_en', '<', now()->subDay())->delete();
    }
}
