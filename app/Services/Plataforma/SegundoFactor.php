<?php

namespace App\Services\Plataforma;

use App\Models\PlataformaUsuario;
use App\Services\Seguridad\CodigoDeUnSoloUso as Totp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * El authenticator de la consola de Netvula.
 *
 * Parte el ingreso en dos: la contraseña ya no entrega la sesión, entrega un
 * pase corto que sólo sirve para presentar el código de seis dígitos. Así una
 * contraseña robada no alcanza.
 *
 * Tres cosas que no son opcionales y por eso están aquí adentro:
 *
 *   - **Códigos de recuperación.** Sin ellos, perder el teléfono es quedarse
 *     afuera del sistema, y el segundo factor pasa de ser seguridad a ser un
 *     riesgo. Se entregan una vez, se guardan con hash y cada uno sirve una
 *     sola vez.
 *   - **Recordar el equipo.** Si pide el código en cada ingreso, a la semana
 *     piden sacarlo.
 *   - **Un tope de intentos.** Seis dígitos son un millón de combinaciones:
 *     sin tope, probarlas es cuestión de tiempo.
 */
class SegundoFactor
{
    /** Lo que dura el pase entre la contraseña y el código. */
    private const MINUTOS_DE_PASE = 5;

    /** Cuántos códigos malos se toleran antes de cortar ese pase. */
    private const INTENTOS = 5;

    /** Cuánto se recuerda un equipo donde ya se comprobó el código. */
    private const DIAS_RECORDADO = 30;

    public const COOKIE = 'nv_consola_equipo';

    // ── Ingreso ─────────────────────────────────────────────────────────────

    /**
     * ¿Hay que pedirle el código a este usuario en esta petición?
     *
     * No, si no lo tiene activo o si viene de un equipo ya comprobado.
     */
    public static function haceFalta(PlataformaUsuario $usuario, Request $request): bool
    {
        if (!$usuario->tieneSegundoFactor()) {
            return false;
        }

        return !self::equipoConocido($usuario, $request);
    }

    /** El pase corto que se cambia por la sesión al presentar el código. */
    public static function abrirPase(PlataformaUsuario $usuario): string
    {
        $pase = Str::random(48);

        Cache::put(self::clave($pase), ['usuario' => $usuario->id, 'intentos' => 0], now()->addMinutes(self::MINUTOS_DE_PASE));

        return $pase;
    }

    /**
     * Cambia el pase y el código por el usuario, o dice qué pasó.
     *
     * Acepta tanto el código del authenticator como uno de recuperación: quien
     * perdió el teléfono tiene que poder entrar, y por la misma puerta.
     *
     * @return array{usuario: ?PlataformaUsuario, motivo: ?string, era_recuperacion: bool}
     */
    public static function comprobar(string $pase, string $codigo, Request $request): array
    {
        $guardado = Cache::get(self::clave($pase));

        if (!is_array($guardado)) {
            return ['usuario' => null, 'motivo' => 'El ingreso venció. Vuelva a poner su correo y contraseña.', 'era_recuperacion' => false];
        }

        $usuario = PlataformaUsuario::find($guardado['usuario'] ?? 0);

        if (!$usuario || !$usuario->activo || !$usuario->tieneSegundoFactor()) {
            Cache::forget(self::clave($pase));

            return ['usuario' => null, 'motivo' => 'No se pudo completar el ingreso.', 'era_recuperacion' => false];
        }

        // El authenticator primero, que es el caso normal.
        if (Totp::sirve((string) $usuario->totp_secreto, $codigo)) {
            Cache::forget(self::clave($pase));
            self::recordarEquipo($usuario, $request);

            return ['usuario' => $usuario, 'motivo' => null, 'era_recuperacion' => false];
        }

        // Un código de recuperación: sirve una vez y se gasta.
        $quedan = (array) ($usuario->totp_recuperacion ?? []);
        $cual = Totp::buscarRecuperacion($quedan, $codigo);

        if ($cual !== null) {
            unset($quedan[$cual]);
            $usuario->forceFill(['totp_recuperacion' => array_values($quedan)])->save();

            Cache::forget(self::clave($pase));
            self::recordarEquipo($usuario, $request);

            Log::warning('[Consola] Ingreso con código de recuperación', [
                'usuario' => $usuario->id, 'quedan' => count($quedan), 'ip' => $request->ip(),
            ]);

            return ['usuario' => $usuario, 'motivo' => null, 'era_recuperacion' => true];
        }

        // Falló. Se cuenta, y al quinto se cierra el pase: hay que volver a
        // poner la contraseña, que es lo que hace inviable probar códigos.
        $guardado['intentos'] = (int) ($guardado['intentos'] ?? 0) + 1;

        if ($guardado['intentos'] >= self::INTENTOS) {
            Cache::forget(self::clave($pase));

            Log::warning('[Consola] Demasiados códigos fallidos', ['usuario' => $usuario->id, 'ip' => $request->ip()]);

            return ['usuario' => null, 'motivo' => 'Demasiados intentos. Vuelva a poner su correo y contraseña.', 'era_recuperacion' => false];
        }

        Cache::put(self::clave($pase), $guardado, now()->addMinutes(self::MINUTOS_DE_PASE));

        $restantes = self::INTENTOS - $guardado['intentos'];

        return [
            'usuario' => null,
            'motivo'  => "Ese código no es. Le quedan {$restantes} intento(s).",
            'era_recuperacion' => false,
        ];
    }

    // ── Activar y quitar ────────────────────────────────────────────────────

    /**
     * Prepara el authenticator: devuelve el secreto y la dirección del QR.
     *
     * Todavía no queda activo. Recién al confirmar con un código se exige,
     * porque si se activara antes y la app quedó mal configurada, el usuario
     * se queda afuera.
     *
     * @return array{secreto: string, direccion: string}
     */
    public static function preparar(PlataformaUsuario $usuario): array
    {
        $secreto = Totp::nuevoSecreto();

        $usuario->forceFill(['totp_secreto' => $secreto, 'totp_activo_en' => null])->save();

        return [
            'secreto'   => $secreto,
            'direccion' => Totp::direccion($secreto, $usuario->email, 'Netvula'),
        ];
    }

    /**
     * Confirma con el primer código y entrega los de recuperación.
     *
     * @return array{ok: bool, motivo: ?string, codigos: list<string>}
     */
    public static function confirmar(PlataformaUsuario $usuario, string $codigo): array
    {
        if (empty($usuario->totp_secreto)) {
            return ['ok' => false, 'motivo' => 'Primero hay que preparar el authenticator.', 'codigos' => []];
        }

        if (!Totp::sirve((string) $usuario->totp_secreto, $codigo)) {
            return ['ok' => false, 'motivo' => 'Ese código no es. Revise que la hora del teléfono esté al día.', 'codigos' => []];
        }

        $recuperacion = Totp::codigosDeRecuperacion();

        $usuario->forceFill([
            'totp_activo_en'    => now(),
            'totp_recuperacion' => $recuperacion['guardados'],
        ])->save();

        Log::info('[Consola] Authenticator activado', ['usuario' => $usuario->id]);

        return ['ok' => true, 'motivo' => null, 'codigos' => $recuperacion['claros']];
    }

    /**
     * Lo quita. Pide la contraseña de nuevo a propósito: si alguien se
     * encuentra una sesión abierta, no puede desarmar la protección sin saber
     * la clave.
     */
    public static function quitar(PlataformaUsuario $usuario): void
    {
        $usuario->forceFill([
            'totp_secreto' => null, 'totp_activo_en' => null, 'totp_recuperacion' => null,
        ])->save();

        DB::table('plataforma_equipos')->where('usuario_id', $usuario->id)->delete();

        Log::warning('[Consola] Authenticator desactivado', ['usuario' => $usuario->id]);
    }

    /** Cuántos códigos de recuperación le quedan sin usar. */
    public static function recuperacionesQueQuedan(PlataformaUsuario $usuario): int
    {
        return count((array) ($usuario->totp_recuperacion ?? []));
    }

    // ── Equipos recordados ──────────────────────────────────────────────────

    private static function equipoConocido(PlataformaUsuario $usuario, Request $request): bool
    {
        $token = (string) $request->cookie(self::COOKIE, '');

        if ($token === '') {
            return false;
        }

        $fila = DB::table('plataforma_equipos')
            ->where('usuario_id', $usuario->id)
            ->where('token_hash', hash('sha256', $token))
            ->where('expira_en', '>', now())
            ->first(['id']);

        if (!$fila) {
            return false;
        }

        DB::table('plataforma_equipos')->where('id', $fila->id)->update(['usado_en' => now()]);

        return true;
    }

    /**
     * Deja marcado este equipo y devuelve la galleta que hay que mandar.
     *
     * El valor se guarda en `pendiente` para que el controlador lo agregue a
     * la respuesta: el servicio no debería estar tocando cabeceras HTTP.
     */
    private static ?string $pendiente = null;

    private static function recordarEquipo(PlataformaUsuario $usuario, Request $request): void
    {
        self::limpiarVencidos();

        $token = Str::random(64);

        DB::table('plataforma_equipos')->insert([
            'usuario_id' => $usuario->id,
            'token_hash' => hash('sha256', $token),
            'nombre'     => Str::limit((string) $request->userAgent(), 110, ''),
            'ip'         => $request->ip(),
            'expira_en'  => now()->addDays(self::DIAS_RECORDADO),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        self::$pendiente = $token;
    }

    /** La galleta del equipo recordado, si el último ingreso generó una. */
    public static function galleta(): ?\Symfony\Component\HttpFoundation\Cookie
    {
        if (self::$pendiente === null) {
            return null;
        }

        $token = self::$pendiente;
        self::$pendiente = null;

        return cookie(
            self::COOKIE,
            $token,
            self::DIAS_RECORDADO * 24 * 60,
            '/',
            null,
            true,   // sólo por https
            true,   // que JavaScript no la lea
            false,
            'Lax',
        );
    }

    private static function limpiarVencidos(): void
    {
        DB::table('plataforma_equipos')->where('expira_en', '<', now())->delete();
    }

    private static function clave(string $pase): string
    {
        return 'consola:2fa:' . hash('sha256', $pase);
    }
}
