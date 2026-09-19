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
            if (strtolower((string) $olt->brand) === 'zte') {
                $fila = (new SnmpZte($snmp))->una($fsp, $ontId);

                // Temperatura, voltaje y láser: sólo por consola en la ZTE.
                if ($fila && $fila['status'] === 'online') {
                    try {
                        $fila = array_merge($fila, array_filter((array) app(\App\Services\OltTelnetDispatcher::class)
                            ->dispatch((int) $olt->id, 'opticaDeOnt', ['fsp' => $fsp, 'ont_id' => $ontId]), fn ($v) => $v !== null));
                    } catch (\Throwable) {
                    }
                }
            }

            if ($fila === null && strtolower((string) $olt->brand) !== 'huawei') {
                $epon = new SnmpEponNscrtv($snmp);
                $fila = $epon->una($fsp, $ontId, self::ifIndex($olt, $epon, "{$fsp}:{$ontId}"));

                // Las GPON C-Data no tienen esa MIB: publican la suya.
                $fila ??= (new SnmpGponCdata($snmp))->una($fsp, $ontId);
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

            return self::normalizar(['fsp' => $fsp, 'ont_id' => $ontId], self::explicar($e->getMessage()));
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

        // La medición de la OLT se guarda por horas; para el estado de una ONT
        // sólo sirve si es de hace unos minutos.
        if (!is_array($senal) || empty($senal['medido_en'])
            || \Carbon\Carbon::parse($senal['medido_en'])->lt(now()->subSeconds(self::VIGENCIA * 5))) {
            return null;
        }

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

    /**
     * Lo que dijo la conexión, en palabras de operador. El texto del socket
     * ("stream_socket_client(): Unable to connect…") no le dice nada a nadie.
     */
    public static function explicar(string $error): string
    {
        return match (true) {
            // Ya viene dicho para el operador: sólo se le saca el detalle técnico.
            str_starts_with($error, 'No se pudo llegar a la OLT')
                => trim(preg_replace('/\s*\[[^\]]*\]\s*$/', '', $error)),
            str_contains($error, 'timed out'), str_contains($error, 'Timeout'), str_contains($error, 'Unable to connect')
                => 'No se pudo llegar a la OLT: la conexión con el nodo se está cortando. Probá de nuevo en un momento.',
            str_contains($error, 'session limit'), str_contains($error, 'Reenter')
                => 'La OLT tiene todas sus sesiones ocupadas. Se liberan solas en unos minutos.',
            default => 'La OLT no respondió: ' . $error,
        };
    }

    /**
     * Un número sólo vale si puede ser una medición de verdad. Cualquier otro
     * es un "sin dato" del equipo que se coló, y mostrarlo engaña.
     */
    private static function enRango(mixed $valor, float $min, float $max): ?float
    {
        if ($valor === null || $valor === '' || !is_numeric($valor)) {
            return null;
        }

        $n = (float) $valor;

        return $n >= $min && $n <= $max ? $n : null;
    }

    /** "00 00 00 00" (la GPON C-Data con la ONT caída) no es un fabricante. */
    private static function fabricante(?string $v): ?string
    {
        $v = trim((string) $v);

        return $v === '' || preg_match('/^(00\s*)+$/', $v) ? null : $v;
    }

    /** @return array<string,mixed> */
    private static function normalizar(array $f, ?string $error = null): array
    {
        $potencia = self::enRango($f['potencia'] ?? null, -60, 10);
        $apagada  = ($f['status'] ?? null) === 'offline';

        return [
            'fsp'         => $f['fsp'] ?? null,
            'ont_id'      => isset($f['ont_id']) ? (int) $f['ont_id'] : null,
            'serial'      => $f['serial'] ?? null,
            'description' => $f['description'] ?? null,
            'status'      => $f['status'] ?? null,
            'modelo'      => $f['modelo'] ?? null,
            'firmware'    => $f['firmware'] ?? null,
            // Vendor ID que la ONT le informó a la OLT (EPON NSCRTV / GPON C-Data).
            'fabricante'  => self::fabricante($f['fabricante'] ?? $f['marca'] ?? null),
            'distancia_m' => $f['distancia_m'] ?? null,
            // Apagada no mide nada: lo que venga son restos de la OLT.
            'potencia'    => $apagada ? null : $potencia,
            'tx'          => $apagada ? null : self::enRango($f['tx'] ?? null, -20, 15),
            'olt_rx'      => $apagada ? null : self::enRango($f['olt_rx'] ?? null, -60, 10),
            'corriente'   => $apagada ? null : self::enRango($f['corriente'] ?? null, 0, 200),
            'voltaje'     => $apagada ? null : self::enRango($f['voltaje'] ?? null, 0, 20),
            'temperatura' => $apagada ? null : self::enRango($f['temperatura'] ?? null, -40, 120),
            'estado'      => $apagada ? 'sin_senal' : SenalDeLaOlt::clasificar($potencia),
            'medido_en'   => now()->toIso8601String(),
            'error'       => $error,
        ];
    }
}
