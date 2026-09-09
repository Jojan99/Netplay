<?php

namespace App\Support;

/**
 * Saca la cédula (y el nombre, si viene) de un mensaje escrito a mano.
 *
 * La gente no responde en formato: manda "1.234.567", "1 234 567", "cc 1234567",
 * "mi cedula es 1234567 y me llamo Juan Pérez", o pega el número con el nombre
 * en la misma línea. Esta clase se queda con lo que sirve.
 *
 * No decide nada sobre el cliente: solo interpreta el texto. La búsqueda en
 * base y qué hacer con el resultado son responsabilidad de quien la usa.
 */
class IdentificacionEnTexto
{
    /**
     * Rango de largo aceptable para una cédula colombiana.
     *
     * En la base de Netplay los documentos van de 6 a 11 dígitos (el grueso en
     * 7, 8 y 10). Se deja 5 como piso por si hay documentos viejos cortos y 12
     * como techo para no descartar un NIT.
     */
    private const LARGO_MIN = 5;
    private const LARGO_MAX = 12;

    /** Palabras que la gente escribe alrededor del dato y no son el nombre. */
    private const RELLENO = [
        'mi', 'cedula', 'cédula', 'cc', 'c.c', 'documento', 'doc', 'identificacion',
        'identificación', 'numero', 'número', 'nro', 'no', 'es', 'soy', 'me', 'llamo',
        'nombre', 'y', 'el', 'la', 'de', 'con', 'buenas', 'buenos', 'dias', 'días',
        'tardes', 'noches', 'hola', 'señor', 'senor', 'señora', 'senora', 'gracias',
        'por', 'favor', 'gracia', 'gracias.', 'gracias,', 'ti', 'nit',
    ];

    /**
     * @return array{documento: ?string, nombre: ?string, candidatos: array<int,string>}
     */
    public static function extraer(?string $texto): array
    {
        $texto = trim((string) $texto);

        if ($texto === '') {
            return ['documento' => null, 'nombre' => null, 'candidatos' => []];
        }

        $candidatos = self::numerosPosibles($texto);

        return [
            'documento'  => $candidatos[0] ?? null,
            'nombre'     => self::nombreProbable($texto, $candidatos),
            'candidatos' => $candidatos,
        ];
    }

    /**
     * Números que podrían ser un documento, en orden de preferencia.
     *
     * Se aceptan puntos, espacios y guiones como separadores de miles porque es
     * como la gente escribe la cédula; se limpian antes de comparar. El orden
     * pone primero los largos típicos de documento para que "1234567" gane
     * sobre un "2" suelto de la misma frase.
     *
     * @return array<int,string>
     */
    public static function numerosPosibles(string $texto): array
    {
        // Un dígito, luego dígitos o separadores, y cierra con dígito.
        preg_match_all('/\d[\d.\s\-]*\d|\d/u', $texto, $m);

        $vistos = [];

        foreach ($m[0] as $bruto) {
            $limpio = preg_replace('/\D/', '', $bruto);

            if ($limpio === '' || $limpio === null) {
                continue;
            }

            $largo = strlen($limpio);

            if ($largo < self::LARGO_MIN || $largo > self::LARGO_MAX) {
                continue;
            }

            // Solo se descarta el número en ceros. Al principio se descartaba
            // cualquier dígito repetido, pero eso tiraba documentos válidos:
            // la cédula de pruebas 999999999 quedaba fuera y el cliente
            // terminaba como "sin registro".
            if (preg_match('/^0+$/', $limpio)) {
                continue;
            }

            $vistos[$limpio] = true;
        }

        $lista = array_keys($vistos);

        // Preferencia: los largos habituales de cédula primero (8 y 10),
        // después el resto de mayor a menor.
        usort($lista, function ($a, $b) {
            $peso = fn($n) => match (strlen($n)) {
                10 => 0,
                8  => 1,
                7  => 2,
                6  => 3,
                11 => 4,
                9  => 5,
                default => 6,
            };

            return [$peso($a), -strlen($a)] <=> [$peso($b), -strlen($b)];
        });

        return $lista;
    }

    /**
     * Lo que queda del mensaje cuando se le sacan los números y el relleno.
     *
     * Si no queda nada con pinta de nombre devuelve null: es preferible no
     * inventar un nombre que guardar "es" o "gracias" como si lo fuera.
     */
    public static function nombreProbable(string $texto, array $candidatos = []): ?string
    {
        // Fuera los números (con sus separadores)
        $limpio = preg_replace('/\d[\d.\s\-]*\d|\d/u', ' ', $texto) ?? $texto;

        // Fuera signos, queda texto y espacios
        $limpio = preg_replace('/[^\p{L}\s\']/u', ' ', $limpio) ?? $limpio;

        $palabras = preg_split('/\s+/u', trim($limpio)) ?: [];

        $utiles = array_values(array_filter($palabras, function ($p) {
            $p = mb_strtolower($p, 'UTF-8');
            return $p !== '' && mb_strlen($p) > 1 && !in_array($p, self::RELLENO, true);
        }));

        // Con una sola palabra suelta suele ser ruido ("tengo", "listo", "ya")
        // y no un nombre. Se pide al menos dos: es preferible quedarse sin
        // nombre que guardar basura, porque el dato que manda es la cédula.
        if (count($utiles) < 2) {
            return null;
        }

        // Un nombre real suele venir en una o varias palabras seguidas; se toman
        // hasta cinco para no arrastrar media conversación.
        $nombre = implode(' ', array_slice($utiles, 0, 5));

        return mb_convert_case(mb_strtolower($nombre, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    }
}
