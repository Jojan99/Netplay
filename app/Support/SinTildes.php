<?php

namespace App\Support;

/**
 * Quita tildes y diéresis y cambia la ñ por n: «Peña Ñandú» → «Pena Nandu». Para lo que va a
 * un equipo (nombre y clave del WiFi), que rechaza esas letras.
 *
 * Con una tabla y no con Normalizer: la extensión intl no está en todos los servidores, y sin
 * ella no cambiaba nada.
 */
final class SinTildes
{
    private const TABLA = [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a', 'Á' => 'A', 'À' => 'A', 'Ä' => 'A', 'Â' => 'A', 'Ã' => 'A',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e', 'É' => 'E', 'È' => 'E', 'Ë' => 'E', 'Ê' => 'E',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i', 'Í' => 'I', 'Ì' => 'I', 'Ï' => 'I', 'Î' => 'I',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o', 'Ó' => 'O', 'Ò' => 'O', 'Ö' => 'O', 'Ô' => 'O', 'Õ' => 'O',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u', 'Ú' => 'U', 'Ù' => 'U', 'Ü' => 'U', 'Û' => 'U',
        'ñ' => 'n', 'Ñ' => 'N', 'ç' => 'c', 'Ç' => 'C',
    ];

    public static function de(string $texto): string
    {
        $limpio = strtr($texto, self::TABLA);

        // Lo que haya quedado como acento suelto (una letra escrita en dos partes).
        return preg_replace('/\p{Mn}+/u', '', $limpio) ?? $limpio;
    }
}
