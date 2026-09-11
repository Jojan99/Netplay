<?php

namespace App\Services\Olt;

/**
 * Qué equipo es la OLT, preguntándoselo por SNMP.
 *
 * Se usan los OID del estándar —los mismos en cualquier fabricante— en vez de
 * los propios de cada marca:
 *
 *   sysDescr              descripción larga que el equipo da de sí mismo
 *   sysObjectID           identifica al fabricante por su número de empresa
 *   entPhysicalModelName  el modelo, cuando el equipo implementa ENTITY-MIB
 *
 * La marca sale del número de empresa registrado en IANA, que es el tramo
 * 1.3.6.1.4.1.<N> del sysObjectID: 2011 es Huawei, 3902 ZTE, 17409 C-Data y
 * 37950 VSOL. Es más confiable que adivinar leyendo la descripción, y hace que
 * un equipo nuevo se reconozca sin tocar código.
 */
class IdentificarOlt
{
    /** OID estándar, iguales en todos los fabricantes. */
    public const SYS_DESCR      = '1.3.6.1.2.1.1.1.0';
    public const SYS_OBJECT_ID  = '1.3.6.1.2.1.1.2.0';
    public const SYS_UPTIME     = '1.3.6.1.2.1.1.3.0';
    public const SYS_CONTACT    = '1.3.6.1.2.1.1.4.0';
    public const SYS_NAME       = '1.3.6.1.2.1.1.5.0';
    public const SYS_LOCATION   = '1.3.6.1.2.1.1.6.0';

    /** ENTITY-MIB: inventario físico del equipo. */
    public const ENT_DESCR      = '1.3.6.1.2.1.47.1.1.1.1.2';
    public const ENT_CLASE      = '1.3.6.1.2.1.47.1.1.1.1.5';
    public const ENT_POSICION   = '1.3.6.1.2.1.47.1.1.1.1.6';
    public const ENT_NOMBRE     = '1.3.6.1.2.1.47.1.1.1.1.7';
    public const ENT_SOFTWARE   = '1.3.6.1.2.1.47.1.1.1.1.10';
    public const ENT_SERIE      = '1.3.6.1.2.1.47.1.1.1.1.11';
    public const ENT_FABRICANTE = '1.3.6.1.2.1.47.1.1.1.1.12';
    public const ENT_MODELO     = '1.3.6.1.2.1.47.1.1.1.1.13';

    /**
     * Número de empresa IANA → marca.
     *
     * @var array<int,string>
     */
    private const FABRICANTES = [
        2011  => 'huawei',
        3902  => 'zte',
        17409 => 'cdata',
        37950 => 'vsol',
        7262  => 'fiberhome',
        3320  => 'nokia',       // antes Alcatel-Lucent
        637   => 'nokia',
        193   => 'ericsson',
        6296  => 'dasan',
        17095 => 'raisecom',
        25355 => 'bdcom',
    ];

    /**
     * @param  callable(string):?string  $leer  devuelve el valor de un OID suelto
     * @param  callable(string):array    $caminar  devuelve una tabla entera
     * @return array<string,mixed>
     */
    public static function desde(callable $leer, callable $caminar): array
    {
        $descripcion = self::limpiar($leer(self::SYS_DESCR));
        $objectId    = self::limpiar($leer(self::SYS_OBJECT_ID));

        $marca = self::marcaDesdeObjectId($objectId) ?: self::marcaDesdeTexto($descripcion);

        $inventario = self::inventario($caminar);

        return [
            'marca'        => $marca,
            'marca_nombre' => self::nombreLindo($marca),
            'modelo'       => self::modelo($inventario, $descripcion),
            'descripcion'  => $descripcion,
            'nombre'       => self::limpiar($leer(self::SYS_NAME)),
            'ubicacion'    => self::limpiar($leer(self::SYS_LOCATION)),
            'contacto'     => self::limpiar($leer(self::SYS_CONTACT)),
            'encendida'    => self::tiempoEncendida($leer(self::SYS_UPTIME)),
            'software'     => self::software($inventario),
            'serie'        => self::deChasis($inventario, 'serie'),
            'tarjetas'     => self::tarjetas($inventario),
            'object_id'    => $objectId,
        ];
    }

    /**
     * ENTITY-MIB completo, indexado por entidad. De aquí salen el modelo del
     * chasis y el listado de tarjetas, sin depender de ninguna marca.
     *
     * @param  callable(string):array  $caminar
     * @return array<string,array<string,mixed>>
     */
    private static function inventario(callable $caminar): array
    {
        $columnas = [
            'descripcion' => self::ENT_DESCR,
            'clase'       => self::ENT_CLASE,
            'posicion'    => self::ENT_POSICION,
            'nombre'      => self::ENT_NOMBRE,
            'software'    => self::ENT_SOFTWARE,
            'serie'       => self::ENT_SERIE,
            'fabricante'  => self::ENT_FABRICANTE,
            'modelo'      => self::ENT_MODELO,
        ];

        $entidades = [];

        foreach ($columnas as $campo => $oid) {
            foreach ($caminar($oid) as $indice => $valor) {
                $indice = trim((string) $indice, '.');
                $entidades[$indice][$campo] = self::limpiar(is_array($valor) ? reset($valor) : $valor);
            }
        }

        // Orden numérico estable: el mismo equipo debe responder siempre igual.
        uksort($entidades, fn ($a, $b) => (int) $a <=> (int) $b);

        return $entidades;
    }

    /**
     * La versión de software del equipo. El chasis y los ventiladores suelen
     * traer un número de placa ("01 01"), mientras la tarjeta de control trae
     * la versión real ("MA5600V800R015C00"), así que se prefiere la que tenga
     * forma de versión de firmware.
     *
     * @param  array<string,array<string,mixed>>  $inventario
     */
    private static function software(array $inventario): ?string
    {
        $suelta = null;

        foreach ($inventario as $entidad) {
            $valor = trim((string) ($entidad['software'] ?? ''));

            if ($valor === '') {
                continue;
            }

            if (preg_match('/[VR]\d|\d+\.\d+/i', $valor)) {
                return $valor;
            }

            $suelta ??= $valor;
        }

        return $suelta;
    }

    /**
     * El modelo del equipo.
     *
     * Las tarjetas y ventiladores nombran el chasis en su descripción —por
     * ejemplo "Fan Box ,MA5608T,H831FCBB0"—, así que el modelo que más se
     * repite en todo el inventario es el del equipo. Eso da "MA5608T" en vez
     * de "FRAME-300", que es lo que responde el chasis por sí mismo.
     *
     * @param  array<string,array<string,mixed>>  $inventario
     */
    private static function modelo(array $inventario, string $descripcion): ?string
    {
        $votos = [];

        foreach ($inventario as $entidad) {
            foreach ([$entidad['descripcion'] ?? '', $entidad['modelo'] ?? ''] as $texto) {
                foreach (self::modelosEn((string) $texto) as $modelo) {
                    $votos[$modelo] = ($votos[$modelo] ?? 0) + 1;
                }
            }
        }

        if ($votos) {
            arsort($votos);

            return (string) array_key_first($votos);
        }

        foreach (self::modelosEn($descripcion) as $modelo) {
            return $modelo;
        }

        // Sin ENTITY-MIB ni patrón conocido, al menos el nombre del chasis.
        return self::deChasis($inventario, 'modelo') ?: self::deChasis($inventario, 'nombre');
    }

    /**
     * Nombres de modelo dentro de un texto. Cubre las familias que hay en el
     * mercado: Huawei MA5600/MA5800, ZTE C300/C320/C600, VSOL V1600, C-Data
     * FD1xxx, FiberHome AN5516, Nokia 7360/7302, BDCOM P3xxx.
     *
     * @return list<string>
     */
    private static function modelosEn(string $texto): array
    {
        $patron = '/\b('
            . 'MA5[0-9]{3}[A-Z]*'            // Huawei MA5608T, MA5800-X7
            . '|C[36][0-9]{2}[A-Z]*'         // ZTE C320, C600
            . '|V1600[A-Z0-9\-]*'           // VSOL V1600G, V1600D
            . '|FD[0-9]{3,4}[A-Z0-9]*'       // C-Data FD1616S
            . '|AN[0-9]{4}[A-Z0-9\-]*'      // FiberHome AN5516
            . '|P3[0-9]{3}[A-Z0-9\-]*'      // BDCOM P3310
            . '|73[0-9]{2}[A-Z0-9\-]*'      // Nokia 7360 ISAM
            . ')\b/i';

        if (!preg_match_all($patron, $texto, $m)) {
            return [];
        }

        return array_values(array_unique(array_map('strtoupper', $m[1])));
    }

    /**
     * Un dato del chasis. entPhysicalClass 3 es el chasis; si no lo declara,
     * se toma la entidad que no tiene padre.
     *
     * @param  array<string,array<string,mixed>>  $inventario
     */
    private static function deChasis(array $inventario, string $campo): ?string
    {
        foreach ($inventario as $entidad) {
            if ((int) filter_var((string) ($entidad['clase'] ?? ''), FILTER_SANITIZE_NUMBER_INT) === 3) {
                $valor = $entidad[$campo] ?? null;

                if ($valor !== null && $valor !== '') {
                    return (string) $valor;
                }
            }
        }

        foreach ($inventario as $entidad) {
            $valor = $entidad[$campo] ?? null;

            if ($valor !== null && $valor !== '') {
                return (string) $valor;
            }
        }

        return null;
    }

    /**
     * Las tarjetas del equipo: clase 9 (module) en ENTITY-MIB. Es lo que el
     * técnico necesita ver —qué placa hay en cada slot, con qué serie— y sale
     * igual en cualquier marca que implemente el estándar.
     *
     * @param  array<string,array<string,mixed>>  $inventario
     * @return list<array<string,mixed>>
     */
    private static function tarjetas(array $inventario): array
    {
        $tarjetas = [];

        foreach ($inventario as $indice => $entidad) {
            if ((int) filter_var((string) ($entidad['clase'] ?? ''), FILTER_SANITIZE_NUMBER_INT) !== 9) {
                continue;
            }

            $tarjetas[] = [
                'indice'      => $indice,
                'nombre'      => $entidad['nombre'] ?? null,
                'modelo'      => $entidad['modelo'] ?? null,
                'descripcion' => $entidad['descripcion'] ?? null,
                'serie'       => $entidad['serie'] ?? null,
                'software'    => $entidad['software'] ?? null,
                'posicion'    => $entidad['posicion'] ?? null,
            ];
        }

        usort($tarjetas, fn ($a, $b) => (int) $a['indice'] <=> (int) $b['indice']);

        return $tarjetas;
    }

    /** El número de empresa vive en el tramo 1.3.6.1.4.1.<N> del sysObjectID. */
    private static function marcaDesdeObjectId(?string $objectId): ?string
    {
        if (!$objectId) {
            return null;
        }

        if (!preg_match('#1\.3\.6\.1\.4\.1\.(\d+)#', $objectId, $m)) {
            return null;
        }

        return self::FABRICANTES[(int) $m[1]] ?? null;
    }

    /** Último recurso: buscar el nombre del fabricante en la descripción. */
    private static function marcaDesdeTexto(?string $texto): ?string
    {
        $texto = strtolower((string) $texto);

        foreach (['huawei', 'zte', 'cdata', 'c-data', 'vsol', 'fiberhome', 'nokia', 'bdcom', 'raisecom', 'dasan'] as $marca) {
            if (str_contains($texto, $marca)) {
                return str_replace('-', '', $marca);
            }
        }

        return null;
    }

    private static function nombreLindo(?string $marca): ?string
    {
        return [
            'huawei'    => 'Huawei',
            'zte'       => 'ZTE',
            'cdata'     => 'C-Data',
            'vsol'      => 'VSOL',
            'fiberhome' => 'FiberHome',
            'nokia'     => 'Nokia',
            'bdcom'     => 'BDCOM',
            'raisecom'  => 'Raisecom',
            'dasan'     => 'DASAN',
            'ericsson'  => 'Ericsson',
        ][$marca] ?? ($marca ? ucfirst($marca) : null);
    }

    /** sysUpTime viene en centésimas de segundo. */
    private static function tiempoEncendida(?string $valor): ?string
    {
        if (!preg_match('/(\d+)/', (string) $valor, $m)) {
            return null;
        }

        $segundos = intdiv((int) $m[1], 100);

        $dias  = intdiv($segundos, 86400);
        $horas = intdiv($segundos % 86400, 3600);
        $mins  = intdiv($segundos % 3600, 60);

        return $dias > 0 ? "{$dias}d {$horas}h {$mins}m" : "{$horas}h {$mins}m";
    }

    /** Saca el tipo que antepone snmpwalk y las comillas. */
    private static function limpiar($valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $texto = (string) $valor;
        $texto = preg_replace('/^\s*(STRING|OID|INTEGER|Timeticks|Gauge32|Counter32|Hex-STRING|IpAddress)\s*:\s*/i', '', $texto);
        $texto = trim($texto, " \t\n\r\0\x0B\"");

        return $texto === '' ? null : $texto;
    }
}
