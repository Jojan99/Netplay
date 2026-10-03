<?php

namespace App\Support;

/**
 * El serial de un equipo, escrito siempre igual.
 *
 * La misma ONT aparece de dos maneras: la etiqueta dice «HWTCBE3F25AA» y la OLT reporta
 * «48575443BE3F25AA», que es lo mismo con las cuatro letras del fabricante en hexadecimal.
 * Comparadas como texto no coinciden, y por eso el inventario no encontraba el equipo que
 * el técnico acababa de instalar. Aquí se llevan las dos a una sola forma.
 */
class Serial
{
    /** Como lo escribe una persona o un lector: mayúsculas, sin espacios ni guiones ni prefijos. */
    public static function limpio(?string $serial): string
    {
        $s = strtoupper(trim((string) $serial));
        // Algunas etiquetas codifican «SN:XXXX» o «S/N XXXX».
        $s = preg_replace('/^(S\/?N|SERIAL|GPON\s*SN|PON\s*SN)\s*[:#]?\s*/', '', $s) ?? $s;

        return preg_replace('/[^0-9A-Z]/', '', $s) ?? '';
    }

    /** La forma única para comparar: las cuatro letras del fabricante + ocho hexadecimales. */
    public static function canonico(?string $serial): string
    {
        $s = self::limpio($serial);

        if (preg_match('/^[0-9A-F]{16}$/', $s)) {
            $letras = '';

            foreach (str_split(substr($s, 0, 8), 2) as $par) {
                $letras .= chr((int) hexdec($par));
            }

            if (preg_match('/^[A-Z]{4}$/', $letras)) {
                return $letras . substr($s, 8);
            }
        }

        return $s;
    }

    /** ¿Tiene pinta de MAC? (12 hexadecimales con al menos una letra, o con separadores). */
    public static function esMac(?string $texto): bool
    {
        $t = strtoupper(trim((string) $texto));

        if (preg_match('/^([0-9A-F]{2}[:\-]){5}[0-9A-F]{2}$/', $t)) {
            return true;
        }

        // Doce dígitos sin letras es un serial numérico, no una MAC.
        return (bool) preg_match('/^(?=.*[A-F])[0-9A-F]{12}$/', $t);
    }

    /** Un serial creíble: entre 6 y 40 caracteres y no es una MAC. */
    public static function valido(?string $serial): bool
    {
        $s = self::limpio($serial);

        return strlen($s) >= 6 && strlen($s) <= 40;
    }
}
