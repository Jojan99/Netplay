<?php

namespace App\Services\Olt;

use App\Models\OltAdmin;
use App\Services\HuaweiSnmpReader;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * La salud de la señal óptica de toda la OLT.
 *
 * Antes sólo se podía ver la potencia de una ONT a la vez, abriendo su detalle,
 * de modo que para saber qué clientes están al límite había que entrar uno por
 * uno. La tabla de diagnóstico óptico trae una fila por ONT, así que en un
 * barrido se puede clasificar la red completa y mostrar directamente los
 * enlaces que van a fallar.
 *
 * Los umbrales son los de una red GPON: por encima de -8 dBm el receptor se
 * satura, hasta -25 dBm el enlace está sano, y de -27 para abajo el cliente
 * empieza a perder paquetes aunque todavía figure "en línea".
 */
class SenalDeLaOlt
{
    private const SATURADA = -8.0;
    private const BUENA    = -25.0;
    private const REGULAR  = -27.0;
    private const BAJA     = -29.0;

    /** Cinco minutos: el barrido es lento y la potencia no cambia por segundo. */
    private const VIGENCIA = 300;

    /**
     * @return array<string,mixed>
     */
    public static function de(OltAdmin $olt, bool $refrescar = false): array
    {
        $clave = "olt:{$olt->id}:senal";

        if (!$refrescar) {
            $guardado = Cache::get($clave);

            if (is_array($guardado)) {
                return $guardado + ['desde_cache' => true];
            }
        }

        $resultado = self::leer($olt);

        if (($resultado['onts'] ?? []) !== []) {
            Cache::put($clave, $resultado, now()->addSeconds(self::VIGENCIA));
        }

        return $resultado + ['desde_cache' => false];
    }

    /**
     * @return array<string,mixed>
     */
    private static function leer(OltAdmin $olt): array
    {
        $vacio = [
            'olt_id'    => (int) $olt->id,
            'medido_en' => now()->toIso8601String(),
            'onts'      => [],
            'resumen'   => self::resumen([]),
            'por_pon'   => [],
            'peores'    => [],
            'error'     => null,
        ];

        try {
            $snmp = new HuaweiSnmpReader($olt);

            // Cada familia de equipos publica la señal en su propia MIB: Huawei
            // en la suya, las EPON con la NSCRTV. Se prueba la EPON primero en
            // las que no son Huawei, para no gastarle un barrido de más a una
            // Huawei con cientos de ONT.
            $epon = strtolower((string) $olt->brand) !== 'huawei'
                ? new SnmpEponNscrtv($snmp)
                : null;

            if ($epon && $epon->esCompatible()) {
                $mediciones = $epon->senal();
                $lista      = $epon->onts();
            } else {
                $mediciones = $snmp->senalDeTodasLasOnts();
                $lista      = null;
            }

            if ($mediciones === []) {
                return array_merge($vacio, [
                    'error' => 'La OLT no devolvió mediciones ópticas por SNMP.',
                ]);
            }

            // El nombre y el serial no están en la tabla óptica; se toman de la
            // lista de ONT autorizadas, que es otro barrido del mismo equipo.
            $datos = [];

            foreach ($lista ?? $snmp->getAuthorizedONTs() as $ont) {
                $datos[$ont['fsp'] . ':' . $ont['ont_id']] = $ont;
            }

            $onts = [];

            foreach ($mediciones as $m) {
                $clave = $m['fsp'] . ':' . $m['ont_id'];
                $ont   = $datos[$clave] ?? [];

                $onts[] = array_merge($m, [
                    'serial'      => $ont['serial']      ?? null,
                    'description' => $ont['description'] ?? null,
                    'status'      => $ont['status']      ?? null,
                    'estado'      => self::clasificar($m['potencia']),
                ]);
            }

            return array_merge($vacio, [
                'onts'    => $onts,
                'resumen' => self::resumen($onts),
                'por_pon' => self::porPon($onts),
                'peores'  => self::peores($onts),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[OLT] No se pudo leer la señal óptica', [
                'olt' => $olt->id, 'error' => $e->getMessage(),
            ]);

            return array_merge($vacio, ['error' => $e->getMessage()]);
        }
    }

    public static function olvidar(OltAdmin $olt): void
    {
        Cache::forget("olt:{$olt->id}:senal");
    }

    /** En qué estado está un enlace según su potencia recibida. */
    public static function clasificar(?float $dbm): string
    {
        return match (true) {
            $dbm === null           => 'sin_dato',
            $dbm > self::SATURADA   => 'saturada',
            $dbm >= self::BUENA     => 'buena',
            $dbm >= self::REGULAR   => 'regular',
            $dbm >= self::BAJA      => 'baja',
            default                 => 'critica',
        };
    }

    /**
     * @param  list<array<string,mixed>>  $onts
     * @return array<string,mixed>
     */
    private static function resumen(array $onts): array
    {
        $conteo = [
            'buena' => 0, 'regular' => 0, 'baja' => 0,
            'critica' => 0, 'saturada' => 0, 'sin_dato' => 0,
        ];

        $potencias = [];

        foreach ($onts as $o) {
            $conteo[$o['estado']] = ($conteo[$o['estado']] ?? 0) + 1;

            if ($o['potencia'] !== null) {
                $potencias[] = $o['potencia'];
            }
        }

        sort($potencias);

        return [
            'total'     => count($onts),
            'medidas'   => count($potencias),
            'conteo'    => $conteo,
            // Las que necesitan una visita: bajas, críticas y saturadas.
            'con_falla' => $conteo['baja'] + $conteo['critica'] + $conteo['saturada'],
            'promedio'  => $potencias ? round(array_sum($potencias) / count($potencias), 2) : null,
            'mediana'   => $potencias ? $potencias[intdiv(count($potencias), 2)] : null,
            'mejor'     => $potencias ? end($potencias) : null,
            'peor'      => $potencias ? $potencias[0] : null,
            'histograma'=> self::histograma($potencias),
        ];
    }

    /**
     * Cuántas ONT hay en cada tramo de 2 dBm. Sirve para ver de un vistazo si
     * la red está centrada donde debe o corrida hacia el límite.
     *
     * @param  list<float>  $potencias
     * @return list<array{desde:float, hasta:float, total:int}>
     */
    private static function histograma(array $potencias): array
    {
        if (!$potencias) {
            return [];
        }

        $tramos = [];

        for ($desde = -34.0; $desde < -14.0; $desde += 2.0) {
            $hasta = $desde + 2.0;

            $tramos[] = [
                'desde' => $desde,
                'hasta' => $hasta,
                'total' => count(array_filter(
                    $potencias,
                    fn (float $p) => $p >= $desde && $p < $hasta
                )),
            ];
        }

        return $tramos;
    }

    /**
     * Cómo está cada puerto PON: cuántas ONT tiene, su potencia promedio y la
     * peor. Un puerto con el promedio corrido suele ser un problema del
     * splitter o de la troncal, no de un cliente.
     *
     * @param  list<array<string,mixed>>  $onts
     * @return list<array<string,mixed>>
     */
    private static function porPon(array $onts): array
    {
        $grupos = [];

        foreach ($onts as $o) {
            $grupos[$o['fsp']][] = $o;
        }

        $filas = [];

        foreach ($grupos as $fsp => $delPuerto) {
            $potencias = array_values(array_filter(array_column($delPuerto, 'potencia'), fn ($p) => $p !== null));

            $filas[] = [
                'fsp'       => $fsp,
                'total'     => count($delPuerto),
                'online'    => count(array_filter($delPuerto, fn ($o) => $o['status'] === 'online')),
                'con_falla' => count(array_filter($delPuerto, fn ($o) => in_array($o['estado'], ['baja', 'critica', 'saturada'], true))),
                'promedio'  => $potencias ? round(array_sum($potencias) / count($potencias), 2) : null,
                'peor'      => $potencias ? min($potencias) : null,
                'mejor'     => $potencias ? max($potencias) : null,
            ];
        }

        usort($filas, fn ($a, $b) => strnatcmp($a['fsp'], $b['fsp']));

        return $filas;
    }

    /**
     * Las ONT que hay que atender primero: de la peor potencia hacia arriba.
     *
     * @param  list<array<string,mixed>>  $onts
     * @return list<array<string,mixed>>
     */
    private static function peores(array $onts, int $cuantas = 25): array
    {
        $conProblema = array_values(array_filter(
            $onts,
            fn ($o) => $o['potencia'] !== null && in_array($o['estado'], ['baja', 'critica', 'saturada'], true)
        ));

        usort($conProblema, fn ($a, $b) => $a['potencia'] <=> $b['potencia']);

        return array_slice($conProblema, 0, $cuantas);
    }
}
