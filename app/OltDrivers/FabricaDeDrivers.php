<?php

namespace App\OltDrivers;

use App\Models\OltAdmin;
use App\OltDrivers\Interfaces\OltDriverInterface;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Elige el driver según la marca de la OLT.
 *
 * Antes se instanciaba HuaweiOltDriver en todos lados, así que una OLT de otra
 * marca recibía comandos Huawei y no respondía nada útil. Ahora la columna
 * olt_admins.brand decide, y agregar una marca es agregar una línea aquí.
 */
class FabricaDeDrivers
{
    /** @var array<string,class-string<OltDriverInterface>> */
    private const DRIVERS = [
        'huawei' => HuaweiOltDriver::class,
        'zte'    => ZteOltDriver::class,
        'cdata'  => CdataOltDriver::class,
        'vsol'   => VsolOltDriver::class,
    ];

    /** Marcas que el mismo driver atiende con otro nombre comercial. */
    private const ALIAS = [
        'c-data' => 'cdata',
        'ecom'   => 'cdata',   // ECOM revende el firmware de C-Data
        'v-sol'  => 'vsol',
        'vsolution' => 'vsol',
        'zxa10'  => 'zte',
    ];

    /**
     * @param  OltAdmin|array<string,mixed>  $olt
     */
    public static function para(OltAdmin|array $olt, object $conexion): OltDriverInterface
    {
        $config = $olt instanceof OltAdmin
            ? array_merge($olt->toArray(), ['enable_password' => $olt->enable_password ?? null])
            : $olt;

        $marca = self::normalizar($config['brand'] ?? null);
        $clase = self::DRIVERS[$marca] ?? null;

        if (!$clase) {
            // Sin marca reconocida no se adivina: mandarle comandos de otro
            // fabricante a una OLT puede dejar sesiones colgadas.
            throw new RuntimeException(
                'La OLT no tiene una marca soportada configurada' .
                ($marca ? " (recibido: {$marca})" : '') .
                '. Marcas disponibles: ' . implode(', ', array_keys(self::DRIVERS)) . '.'
            );
        }

        Log::debug('[OLT] Driver elegido', ['marca' => $marca, 'driver' => class_basename($clase)]);

        return new $clase($conexion, $config);
    }

    /** ¿Hay driver para esta marca? */
    public static function soporta(?string $marca): bool
    {
        return isset(self::DRIVERS[self::normalizar($marca)]);
    }

    /**
     * Marcas soportadas, para armar el selector de la pantalla de OLT.
     *
     * @return list<array{valor:string, nombre:string}>
     */
    public static function marcas(): array
    {
        $nombres = [
            'huawei' => 'Huawei (MA5600T / MA5608T / MA5800)',
            'zte'    => 'ZTE (C300 / C320 / C600)',
            'cdata'  => 'C-Data / ECOM (FD15xx / FD16xx)',
            'vsol'   => 'V-SOL (V1600G / V1600D)',
        ];

        return array_map(
            fn (string $valor) => ['valor' => $valor, 'nombre' => $nombres[$valor] ?? ucfirst($valor)],
            array_keys(self::DRIVERS)
        );
    }

    private static function normalizar(?string $marca): string
    {
        $marca = strtolower(trim((string) $marca));

        return self::ALIAS[$marca] ?? $marca;
    }
}
