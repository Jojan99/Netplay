<?php

namespace App\Services\Olt;

use App\Models\OltAdmin;
use App\Services\HuaweiSnmpReader;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Cómo está ahora mismo una ONT: si está en línea, su señal y su equipo.
 *
 * El estado guardado en olt_onts es el de la última sincronización, que puede
 * tener horas: una ONT figuraba "offline" con el cliente navegando. Esto le
 * pregunta a la OLT por esa ONT sola, sin recorrer la red entera.
 *
 * Si la pantalla de señal ya midió toda la OLT hace poco, se usa esa fila:
 * es la misma medición y no cuesta otra consulta.
 */
class EstadoDeUnaOnt
{
    /** Un minuto: lo justo para que abrir y cerrar la ficha no repita la consulta. */
    private const VIGENCIA = 60;

    /** @return array<string,mixed> */
    public static function de(OltAdmin $olt, string $fsp, int $ontId, bool $refrescar = false): array
    {
        $clave = "olt:{$olt->id}:ont:{$fsp}:{$ontId}:vivo";

        if (!$refrescar) {
            $guardado = Cache::get($clave);

            if (is_array($guardado)) {
                return $guardado + ['desde_cache' => true];
            }

            $fila = self::desdeLaSenal($olt, $fsp, $ontId);

            if ($fila) {
                return $fila + ['desde_cache' => true];
            }
        }

        $resultado = self::leer($olt, $fsp, $ontId);

        if ($resultado['error'] === null) {
            Cache::put($clave, $resultado, now()->addSeconds(self::VIGENCIA));
        }

        return $resultado + ['desde_cache' => false];
    }

    /** @return array<string,mixed> */
    private static function leer(OltAdmin $olt, string $fsp, int $ontId): array
    {
        try {
            $snmp = new HuaweiSnmpReader($olt);
            $fila = null;

            // Las EPON publican la ONU en la MIB NSCRTV; si la OLT no la tiene,
            // una() no encuentra la ONU y se prueba la de Huawei.
            if (strtolower((string) $olt->brand) !== 'huawei') {
                $epon = new SnmpEponNscrtv($snmp);
                $fila = $epon->una($fsp, $ontId, self::ifIndex($olt, $epon, "{$fsp}:{$ontId}"));
            }

            if ($fila === null) {
                $fila = self::deHuawei($snmp->getOntInfo($fsp, $ontId));
            }

            if (!empty($fila['error'])) {
                return self::normalizar(['fsp' => $fsp, 'ont_id' => $ontId], $fila['error']);
            }

            return self::normalizar($fila);
        } catch (\Throwable $e) {
            Log::warning('[OLT] No se pudo leer la ONT', [
                'olt' => $olt->id, 'fsp' => $fsp, 'ont' => $ontId, 'error' => $e->getMessage(),
            ]);

            return self::normalizar(['fsp' => $fsp, 'ont_id' => $ontId], 'La OLT no respondió: ' . $e->getMessage());
        }
    }

    /**
     * El ifIndex de la ONU, guardado por OLT. Si la ONU no está en el mapa
     * guardado —la autorizaron después— se vuelve a leer una vez.
     */
    private static function ifIndex(OltAdmin $olt, SnmpEponNscrtv $epon, string $clave): ?int
    {
        $cache = "olt:{$olt->id}:epon:ifindex";
        $mapa  = Cache::get($cache);

        if (!is_array($mapa) || !isset($mapa[$clave])) {
            $mapa = $epon->mapa();

            if ($mapa !== []) {
                Cache::put($cache, $mapa, now()->addHours(6));
            }
        }

        return $mapa[$clave] ?? null;
    }

    /** La fila de esta ONT en la última medición de toda la OLT, si hay. */
    private static function desdeLaSenal(OltAdmin $olt, string $fsp, int $ontId): ?array
    {
        $senal = Cache::get("olt:{$olt->id}:senal");

        foreach (($senal['onts'] ?? []) as $o) {
            if (($o['fsp'] ?? null) === $fsp && (int) ($o['ont_id'] ?? -1) === $ontId) {
                return array_merge(self::normalizar($o), ['medido_en' => $senal['medido_en'] ?? null]);
            }
        }

        return null;
    }

    /** El lector de Huawei nombra los campos en inglés. */
    private static function deHuawei(array $info): array
    {
        return [
            'fsp'         => $info['fsp'] ?? null,
            'ont_id'      => $info['ont_id'] ?? null,
            'serial'      => $info['serial'] ?? null,
            'description' => $info['description'] ?? null,
            'status'      => $info['status'] ?? null,
            'potencia'    => $info['rx_power'] ?? null,
            'tx'          => $info['tx_power'] ?? null,
            'olt_rx'      => $info['olt_rx_power'] ?? null,
            'corriente'   => $info['laser_current'] ?? null,
            'voltaje'     => $info['voltage'] ?? null,
            'temperatura' => $info['temperature'] ?? null,
            'error'       => $info['error'] ?? null,
        ];
    }

    /** @return array<string,mixed> */
    private static function normalizar(array $f, ?string $error = null): array
    {
        $potencia = isset($f['potencia']) ? (float) $f['potencia'] : null;

        return [
            'fsp'         => $f['fsp'] ?? null,
            'ont_id'      => isset($f['ont_id']) ? (int) $f['ont_id'] : null,
            'serial'      => $f['serial'] ?? null,
            'description' => $f['description'] ?? null,
            'status'      => $f['status'] ?? null,
            'modelo'      => $f['modelo'] ?? null,
            'firmware'    => $f['firmware'] ?? null,
            'distancia_m' => $f['distancia_m'] ?? null,
            'potencia'    => $potencia,
            'tx'          => $f['tx'] ?? null,
            'olt_rx'      => $f['olt_rx'] ?? null,
            'corriente'   => $f['corriente'] ?? null,
            'voltaje'     => $f['voltaje'] ?? null,
            'temperatura' => $f['temperatura'] ?? null,
            'estado'      => SenalDeLaOlt::clasificar($potencia),
            'medido_en'   => now()->toIso8601String(),
            'error'       => $error,
        ];
    }
}
