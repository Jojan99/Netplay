<?php

namespace App\Services\Importador;

use Illuminate\Support\Facades\DB;

/**
 * Qué parte de la base ya está actualizada.
 *
 * La segunda tanda del importador (factura del saldo, grupo y router por
 * cliente) agrega columnas. Mientras no se corra esa migración el importador
 * sigue funcionando sin esas funciones, en vez de fallar.
 */
class Esquema
{
    public static function filasAmpliadas(): bool
    {
        static $si = null;

        return $si ??= self::hayColumna('importacion_filas', 'grupo_elegido');
    }

    public static function conceptoEnFacturas(): bool
    {
        static $si = null;

        return $si ??= self::hayColumna('det_facturations', 'concepto');
    }

    /**
     * Con SHOW COLUMNS y no con Schema::hasColumn: es más barato que consultar
     * information_schema y, además, ve las tablas temporales, que es como se
     * prueba la importación sin escribir en la base de verdad.
     */
    private static function hayColumna(string $tabla, string $columna): bool
    {
        try {
            // Sin parámetros ligados: MySQL no admite "LIKE ?" en un SHOW preparado.
            $columna = preg_replace('/[^a-z0-9_]/i', '', $columna);

            return DB::select("SHOW COLUMNS FROM `{$tabla}` LIKE '{$columna}'") !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    /** Lo que falta correr, en castellano, o cadena vacía si está todo. */
    public static function loQueFalta(): string
    {
        if (self::filasAmpliadas() && self::conceptoEnFacturas()) {
            return '';
        }

        return 'Falta aplicar la actualización de la base del importador (migración 2026_09_17_000003 o el archivo importador-2.sql).';
    }
}
