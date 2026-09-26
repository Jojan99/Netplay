<?php

namespace App\Services\Importador;

/**
 * Parte el nombre completo en nombres y apellidos.
 *
 * WispHub (y la mayoría de las exportaciones) traen todo junto en una sola
 * columna: "BRAYAN JESUS CASTELLANOS RUIZ". Aquí se separa con la costumbre
 * colombiana —dos nombres y dos apellidos— pero el administrador puede elegir
 * otra regla desde la pantalla, porque ningún criterio acierta siempre:
 * "MARIA DEL CARMEN DE LA HOZ" no se parte igual que "JUAN PEREZ".
 */
class SeparadorDeNombres
{
    /** Las reglas que puede elegir el administrador. */
    public const REGLAS = [
        'auto'     => 'Automática: las dos últimas palabras son los apellidos (y las partículas "de", "la", "del" van con el apellido)',
        'ultimas2' => 'Las dos últimas palabras son los apellidos',
        'ultima1'  => 'La última palabra es el apellido',
        'primera1' => 'La primera palabra es el nombre, el resto apellidos',
        'primeras2' => 'Las dos primeras palabras son los nombres, el resto apellidos',
        'todo'     => 'Todo junto en "nombres" (apellido vacío)',
    ];

    /** Partículas que van pegadas al apellido que sigue. */
    private const PARTICULAS = ['de', 'del', 'la', 'las', 'los', 'san', 'santa', 'da', 'das', 'dos', 'di', 'von', 'van', 'mac', 'mc'];

    /**
     * @return array{0:string,1:string} [nombres, apellidos]
     */
    public static function aplicar(string $completo, string $regla = 'auto'): array
    {
        $completo = trim(preg_replace('/\s+/u', ' ', $completo));

        if ($completo === '') {
            return ['', ''];
        }

        // "PEREZ GOMEZ, JUAN CARLOS": con coma el orden es apellidos primero.
        if (substr_count($completo, ',') === 1) {
            [$izq, $der] = array_map('trim', explode(',', $completo));
            if ($izq !== '' && $der !== '') {
                return [$der, $izq];
            }
        }

        $palabras = explode(' ', $completo);

        if (count($palabras) === 1) {
            return $regla === 'primera1' ? [$palabras[0], ''] : [$palabras[0], ''];
        }

        return match ($regla) {
            'todo'      => [$completo, ''],
            'ultima1'   => [implode(' ', array_slice($palabras, 0, -1)), end($palabras)],
            'primera1'  => [$palabras[0], implode(' ', array_slice($palabras, 1))],
            'primeras2' => count($palabras) <= 2
                ? [$palabras[0], $palabras[1]]
                : [implode(' ', array_slice($palabras, 0, 2)), implode(' ', array_slice($palabras, 2))],
            'ultimas2'  => count($palabras) <= 2
                ? [$palabras[0], $palabras[1]]
                : [implode(' ', array_slice($palabras, 0, -2)), implode(' ', array_slice($palabras, -2))],
            default     => self::automatica($palabras),
        };
    }

    /**
     * Dos apellidos al final, pero las partículas se llevan la palabra que
     * sigue: en "MARIA DEL CARMEN DE LA HOZ", "DE LA HOZ" es un solo apellido.
     *
     * @param  string[] $palabras
     * @return array{0:string,1:string}
     */
    private static function automatica(array $palabras): array
    {
        // Se arman "bloques": una partícula se pega a lo que viene después.
        $bloques = [];
        $pendiente = '';

        foreach ($palabras as $p) {
            $esParticula = in_array(mb_strtolower($p), self::PARTICULAS, true);

            if ($esParticula) {
                $pendiente = $pendiente === '' ? $p : $pendiente . ' ' . $p;
                continue;
            }

            $bloques[] = $pendiente === '' ? $p : $pendiente . ' ' . $p;
            $pendiente = '';
        }

        if ($pendiente !== '') {
            $bloques[] = $pendiente;
        }

        if (count($bloques) <= 1) {
            return [implode(' ', $palabras), ''];
        }
        if (count($bloques) === 2) {
            return [$bloques[0], $bloques[1]];
        }
        if (count($bloques) === 3) {
            // Un nombre y dos apellidos: lo más común cuando son tres bloques.
            return [$bloques[0], $bloques[1] . ' ' . $bloques[2]];
        }

        return [
            implode(' ', array_slice($bloques, 0, -2)),
            implode(' ', array_slice($bloques, -2)),
        ];
    }

    /**
     * Cómo quedarían unos cuantos nombres del archivo con cada regla, para que
     * el administrador vea el efecto antes de importar.
     *
     * @param  string[] $completos
     * @return array<int, array{completo:string, nombres:string, apellidos:string}>
     */
    public static function ejemplos(array $completos, string $regla, int $cuantos = 5): array
    {
        $vistos = [];
        $ejemplos = [];

        foreach ($completos as $c) {
            $c = trim((string) $c);

            if ($c === '' || isset($vistos[$c])) {
                continue;
            }

            $vistos[$c] = true;
            [$n, $a] = self::aplicar($c, $regla);
            $ejemplos[] = ['completo' => $c, 'nombres' => $n, 'apellidos' => $a];

            if (count($ejemplos) >= $cuantos) {
                break;
            }
        }

        return $ejemplos;
    }
}
