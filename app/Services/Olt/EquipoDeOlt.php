<?php

namespace App\Services\Olt;

use App\Models\OltAdmin;
use App\Services\HuaweiSnmpReader;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * La ficha del equipo: qué OLT es, qué tarjetas tiene y en qué estado están sus
 * puertos.
 *
 * Todo sale de los OID del estándar (SNMPv2-MIB, ENTITY-MIB, IF-MIB), así que
 * funciona igual en Huawei, ZTE, C-Data o V-SOL sin un solo comando propio del
 * fabricante. Es la información con la que la pantalla dibuja el frente del
 * equipo, en lugar de mostrar una foto de catálogo que puede no ser el modelo
 * que el operador tiene instalado.
 */
class EquipoDeOlt
{
    /** IF-MIB, lo mismo en cualquier marca. */
    private const IF_NOMBRE  = '1.3.6.1.2.1.31.1.1.1.1';   // ifName
    private const IF_ALIAS   = '1.3.6.1.2.1.31.1.1.1.18';  // ifAlias
    private const IF_ESTADO  = '1.3.6.1.2.1.2.2.1.8';      // ifOperStatus
    private const IF_ADMIN   = '1.3.6.1.2.1.2.2.1.7';      // ifAdminStatus
    private const IF_VELOC   = '1.3.6.1.2.1.31.1.1.1.15';  // ifHighSpeed (Mbps)

    /** Media hora: alcanza para no repetir el walk en cada visita. */
    private const VIGENCIA = 1800;

    /**
     * @return array<string,mixed>
     */
    public static function de(OltAdmin $olt, bool $refrescar = false): array
    {
        $clave = "olt:{$olt->id}:equipo";

        if (!$refrescar) {
            $guardado = Cache::get($clave);

            if (is_array($guardado)) {
                return $guardado + ['desde_cache' => true];
            }
        }

        $ficha = self::leer($olt);

        // Sólo se guarda lo que sirvió: si el equipo no respondió, la próxima
        // visita vuelve a intentarlo en vez de dejar la pantalla vacía.
        if (($ficha['responde'] ?? false) === true) {
            Cache::put($clave, $ficha, now()->addSeconds(self::VIGENCIA));
        }

        return $ficha + ['desde_cache' => false];
    }

    public static function olvidar(OltAdmin $olt): void
    {
        Cache::forget("olt:{$olt->id}:equipo");
    }

    /**
     * @return array<string,mixed>
     */
    private static function leer(OltAdmin $olt): array
    {
        $base = [
            'olt_id'           => (int) $olt->id,
            'nombre_plataforma'=> $olt->name,
            'marca_configurada'=> $olt->brand,
            'marca_soportada'  => \App\OltDrivers\FabricaDeDrivers::soporta($olt->brand),
            'responde'         => false,
            'error'            => null,
            'identidad'        => null,
            'puertos_pon'      => [],
            'uplinks'          => [],
            'tarjetas'         => [],
        ];

        try {
            $snmp      = new HuaweiSnmpReader($olt);
            $identidad = $snmp->identidad();

            if (empty($identidad['descripcion']) && empty($identidad['modelo'])) {
                return array_merge($base, [
                    'error' => 'La OLT no respondió por SNMP. Revise comunidad, versión y acceso.',
                ]);
            }

            $tarjetas = $identidad['tarjetas'] ?? [];
            unset($identidad['tarjetas']);

            $interfaces = self::interfaces($snmp);

            // La marca real que declara el equipo manda sobre la configurada:
            // si no coinciden hay que avisarlo, porque el driver que se usa
            // sale de la configuración y los comandos no le van a servir.
            $base['marca_detectada'] = $identidad['marca'] ?? null;
            $base['marca_coincide']  = $identidad['marca'] === null
                || strtolower((string) $olt->brand) === $identidad['marca'];

            return array_merge($base, [
                'responde'    => true,
                'identidad'   => $identidad,
                'tarjetas'    => $tarjetas,
                'puertos_pon' => $interfaces['pon'],
                'uplinks'     => $interfaces['uplinks'],
            ]);
        } catch (\Throwable $e) {
            Log::warning('[OLT] No se pudo leer la ficha del equipo', [
                'olt' => $olt->id, 'error' => $e->getMessage(),
            ]);

            return array_merge($base, ['error' => $e->getMessage()]);
        }
    }

    /**
     * Los puertos del equipo, separados entre fibra y subida.
     *
     * @return array{pon:list<array<string,mixed>>, uplinks:list<array<string,mixed>>}
     */
    private static function interfaces(HuaweiSnmpReader $snmp): array
    {
        $nombres = self::columna($snmp, self::IF_NOMBRE);

        if (!$nombres) {
            return ['pon' => [], 'uplinks' => []];
        }

        $alias     = self::columna($snmp, self::IF_ALIAS);
        $estados   = self::columna($snmp, self::IF_ESTADO);
        $admin     = self::columna($snmp, self::IF_ADMIN);
        $velocidad = self::columna($snmp, self::IF_VELOC);

        $pon = $uplinks = [];

        foreach ($nombres as $indice => $nombre) {
            $enlace = self::estado($estados[$indice] ?? '');

            $puerto = [
                'ifindex'   => $indice,
                'nombre'    => $nombre,
                'alias'     => ($alias[$indice] ?? '') ?: null,
                'enlace'    => $enlace,
                'habilitado'=> self::estado($admin[$indice] ?? '') !== 'down',
                'mbps'      => (int) filter_var((string) ($velocidad[$indice] ?? ''), FILTER_SANITIZE_NUMBER_INT) ?: null,
            ];

            if (preg_match('/(?:g|e|x|10g)?pon/i', $nombre)) {
                // "GPON 0/0/9" → el F/S/P con el que trabaja la plataforma.
                $puerto['fsp'] = preg_match('#(\d+/\d+/\d+)\s*$#', $nombre, $m) ? $m[1] : null;

                if ($puerto['fsp'] === null && preg_match('#(\d+)/(\d+)\s*$#', $nombre, $m)) {
                    $puerto['fsp'] = "{$m[1]}/0/{$m[2]}";   // V-SOL numera en dos niveles
                }

                $pon[] = $puerto;
                continue;
            }

            // Los uplinks son los ethernet; se dejan fuera los lógicos.
            if (preg_match('/(?:gigabit|ethernet|^ge|^xge|^eth|^10ge|uplink)/i', $nombre)) {
                $uplinks[] = $puerto;
            }
        }

        usort($pon, fn ($a, $b) => strnatcasecmp((string) $a['nombre'], (string) $b['nombre']));
        usort($uplinks, fn ($a, $b) => strnatcasecmp((string) $a['nombre'], (string) $b['nombre']));

        return ['pon' => $pon, 'uplinks' => $uplinks];
    }

    /**
     * Una columna de IF-MIB como [ifIndex => valor limpio].
     *
     * @return array<int,string>
     */
    private static function columna(HuaweiSnmpReader $snmp, string $oid): array
    {
        $columna = [];

        try {
            foreach ($snmp->walkRaw($oid) as $sufijo => $valor) {
                $indice = (int) explode('.', trim((string) $sufijo, '.'))[0];

                if ($indice <= 0) {
                    continue;
                }

                $texto = preg_replace(
                    '/^\s*(STRING|INTEGER|Gauge32|Counter32|Hex-STRING)\s*:\s*/i',
                    '',
                    (string) $valor
                );

                $columna[$indice] = trim($texto, " \t\r\n\"'");
            }
        } catch (\Throwable $e) {
            Log::debug('[OLT] Columna IF-MIB sin respuesta', ['oid' => $oid, 'error' => $e->getMessage()]);
        }

        return $columna;
    }

    /** ifOperStatus / ifAdminStatus: 1 arriba, 2 abajo, el resto en tránsito. */
    private static function estado(string $valor): string
    {
        return match ((int) filter_var($valor, FILTER_SANITIZE_NUMBER_INT)) {
            1       => 'up',
            2       => 'down',
            0       => 'desconocido',
            default => 'transicion',
        };
    }
}
