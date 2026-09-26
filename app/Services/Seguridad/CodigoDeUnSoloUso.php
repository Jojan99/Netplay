<?php

namespace App\Services\Seguridad;

/**
 * Los códigos de seis dígitos del authenticator (TOTP, RFC 6238).
 *
 * Está escrito aquí y no traído de una librería a propósito: el algoritmo son
 * treinta líneas —un HMAC, un truncado y un módulo— y agregar una dependencia
 * a producción para eso trae más riesgo del que quita. Está comprobado contra
 * los vectores de prueba del RFC, que es lo que garantiza que Google
 * Authenticator, Authy y cualquier otra app den el mismo número.
 *
 * Sirve para cualquier app: es un estándar, no atamos a ninguna.
 */
class CodigoDeUnSoloUso
{
    /** Cada código vale 30 segundos, como todas las apps. */
    public const VENTANA = 30;

    public const DIGITOS = 6;

    /**
     * Cuántas ventanas para atrás y para adelante se aceptan.
     *
     * El reloj del teléfono nunca está exactamente igual que el del servidor.
     * Con una ventana se toleran ±30 s, que cubre el desfase normal sin
     * ampliar de verdad el tiempo en que un código sirve.
     */
    public const TOLERANCIA = 1;

    /** Un secreto nuevo, en base32, que es lo que leen las apps. */
    public static function nuevoSecreto(int $bytes = 20): string
    {
        return self::base32(random_bytes($bytes));
    }

    /** El código que corresponde a este secreto en este momento. */
    public static function codigo(string $secreto, ?int $momento = null): string
    {
        $contador = intdiv($momento ?? time(), self::VENTANA);
        $clave = self::deBase32($secreto);

        $hash = hash_hmac('sha1', pack('N*', 0, $contador), $clave, true);

        // Truncado dinámico: los cuatro últimos bits dicen de dónde sacar el
        // número, para que no sea siempre el mismo pedazo del hash.
        $desde = ord($hash[19]) & 0x0F;
        $numero = ((ord($hash[$desde]) & 0x7F) << 24)
            | ((ord($hash[$desde + 1]) & 0xFF) << 16)
            | ((ord($hash[$desde + 2]) & 0xFF) << 8)
            | (ord($hash[$desde + 3]) & 0xFF);

        return str_pad((string) ($numero % (10 ** self::DIGITOS)), self::DIGITOS, '0', STR_PAD_LEFT);
    }

    /**
     * ¿Este código sirve?
     *
     * La comparación es de tiempo constante: comparar con `===` deja medir
     * cuántos dígitos acertó por lo que tarda en contestar.
     */
    public static function sirve(string $secreto, string $codigo, ?int $momento = null): bool
    {
        $codigo = preg_replace('/\D/', '', $codigo);

        if (strlen((string) $codigo) !== self::DIGITOS) {
            return false;
        }

        $ahora = $momento ?? time();

        for ($i = -self::TOLERANCIA; $i <= self::TOLERANCIA; $i++) {
            if (hash_equals(self::codigo($secreto, $ahora + $i * self::VENTANA), (string) $codigo)) {
                return true;
            }
        }

        return false;
    }

    /**
     * La dirección otpauth:// que se convierte en el QR.
     *
     * El emisor y la cuenta son lo que la app muestra en la lista, así que
     * tienen que decir de qué sistema y de quién es: alguien con tres
     * authenticators configurados no tiene por qué adivinar cuál es cuál.
     */
    public static function direccion(string $secreto, string $cuenta, string $emisor): string
    {
        // Los dos puntos que separan emisor y cuenta van LITERALES, y la
        // arroba también. Escapando la etiqueta entera —«Netvula%3Ano-reply%40…»—
        // varias apps contestan «no se puede escanear este código QR»: el
        // separador deja de reconocerse.
        $etiqueta = self::parte($emisor) . ':' . self::parte($cuenta);

        return 'otpauth://totp/' . $etiqueta . '?' . http_build_query([
            'secret' => $secreto,
            'issuer' => $emisor,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITOS,
            'period' => self::VENTANA,
        ]);
    }

    /** Un trozo de la etiqueta: escapado, pero dejando la arroba a la vista. */
    private static function parte(string $texto): string
    {
        return str_replace(['%40', '%20'], ['@', '%20'], rawurlencode(trim($texto)));
    }

    /**
     * Códigos de recuperación, para el día que se pierde el teléfono.
     *
     * Se entregan una sola vez y se guardan con hash: si alguien lee la base
     * no puede entrar con ellos. Sin esto, perder el teléfono es quedarse
     * afuera del sistema y ahí el 2FA deja de ser seguridad y pasa a ser un
     * riesgo.
     *
     * @return array{claros: list<string>, guardados: list<string>}
     */
    public static function codigosDeRecuperacion(int $cuantos = 10): array
    {
        $claros = [];
        $guardados = [];

        for ($i = 0; $i < $cuantos; $i++) {
            // Sin vocales ni caracteres que se confunden al copiarlos a mano.
            $codigo = strtoupper(bin2hex(random_bytes(4)));
            $codigo = substr($codigo, 0, 4) . '-' . substr($codigo, 4);

            $claros[] = $codigo;
            $guardados[] = hash('sha256', $codigo);
        }

        return ['claros' => $claros, 'guardados' => $guardados];
    }

    /**
     * Busca el código entre los guardados y devuelve su posición, o null.
     *
     * @param  list<string>  $guardados
     */
    public static function buscarRecuperacion(array $guardados, string $codigo): ?int
    {
        $limpio = strtoupper(trim($codigo));
        $hash = hash('sha256', $limpio);

        foreach ($guardados as $i => $g) {
            if (hash_equals((string) $g, $hash)) {
                return $i;
            }
        }

        return null;
    }

    // ── Base32, que es lo que hablan las apps ───────────────────────────────

    private const ALFABETO = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function base32(string $bytes): string
    {
        $bits = '';

        foreach (str_split($bytes) as $b) {
            $bits .= str_pad(decbin(ord($b)), 8, '0', STR_PAD_LEFT);
        }

        $salida = '';

        foreach (str_split($bits, 5) as $trozo) {
            $salida .= self::ALFABETO[bindec(str_pad($trozo, 5, '0', STR_PAD_RIGHT))];
        }

        return $salida;
    }

    public static function deBase32(string $texto): string
    {
        $texto = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $texto));
        $bits = '';

        foreach (str_split($texto) as $c) {
            $bits .= str_pad(decbin((int) strpos(self::ALFABETO, $c)), 5, '0', STR_PAD_LEFT);
        }

        $salida = '';

        foreach (str_split($bits, 8) as $trozo) {
            if (strlen($trozo) === 8) {
                $salida .= chr(bindec($trozo));
            }
        }

        return $salida;
    }
}
