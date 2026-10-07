<?php

namespace App\Support;

/**
 * La búsqueda de los cuadros de texto: por palabras, en cualquier orden.
 *
 * Antes cada pantalla buscaba el texto entero, tal cual, dentro de «nombres» o
 * de «nombres apellidos». «Gabriel Giraldo» no encontraba a «GABRIEL DE JESUS
 * GIRALDO SUESCUN» (las dos palabras no van juntas), «Giraldo Gabriel» tampoco
 * (otro orden) y «311 629 0588» no encontraba el celular «+573116290588».
 *
 * Aquí cada palabra tiene que aparecer en alguna de las columnas, sin importar
 * el orden ni en cuál: «gabriel giraldo», «giraldo gabriel» o «gabriel 1041»
 * encuentran al mismo cliente. Si lo escrito es un número (cédula, celular), se
 * compara además solo por sus dígitos, sin espacios, guiones ni el +57.
 *
 * Tildes y mayúsculas ya las ignora la base (utf8mb4_unicode_ci).
 *
 * Uso:
 *   BusquedaPorPalabras::aplicar($query, $texto,
 *       ['ud.names', 'ud.lastname', 'ud.dni', 'ud.email'],
 *       ['ud.dni', 'ud.phone']);
 */
class BusquedaPorPalabras
{
    /** Más palabras que esto no afinan nada y hacen la consulta pesada. */
    private const MAXIMO_DE_PALABRAS = 6;

    /** Con menos dígitos, «buscar por número» encontraría a medio mundo. */
    private const MINIMO_DE_DIGITOS = 5;

    /**
     * @param \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder $query
     * @param list<string> $columnas Donde se busca cada palabra (columnas o expresiones SQL de confianza).
     * @param list<string> $columnasDeNumeros Donde se busca lo escrito como número, solo por dígitos.
     */
    public static function aplicar($query, ?string $texto, array $columnas, array $columnasDeNumeros = [])
    {
        $palabras = self::palabras($texto);

        if (!$palabras || !$columnas) {
            return $query;
        }

        $digitos = preg_replace('/\D/', '', (string) $texto);
        $esNumero = $columnasDeNumeros
            && strlen($digitos) >= self::MINIMO_DE_DIGITOS
            // «311 629 0588» o «+57 311-629-0588» son un número; «calle 45 # 12-30», no.
            && preg_match('/^[\d\s+\-().]+$/', trim((string) $texto)) === 1;

        return $query->where(function ($w) use ($palabras, $columnas, $esNumero, $digitos, $columnasDeNumeros) {
            // Todas las palabras, cada una en alguna columna.
            $w->where(function ($todas) use ($palabras, $columnas) {
                foreach ($palabras as $palabra) {
                    $like = '%' . addcslashes($palabra, '%_\\') . '%';

                    $todas->where(function ($una) use ($columnas, $like) {
                        foreach ($columnas as $columna) {
                            $una->orWhereRaw("{$columna} LIKE ?", [$like]);
                        }
                    });
                }
            });

            if ($esNumero) {
                foreach ($columnasDeNumeros as $columna) {
                    $w->orWhereRaw("REGEXP_REPLACE(COALESCE({$columna}, ''), '[^0-9]', '') LIKE ?", ['%' . $digitos . '%']);
                }
            }
        });
    }

    /** @return list<string> */
    public static function palabras(?string $texto): array
    {
        $partes = preg_split('/\s+/u', trim((string) $texto), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_slice(array_values(array_unique($partes)), 0, self::MAXIMO_DE_PALABRAS);
    }
}
