<?php

namespace App\Services\Olt;

use App\Models\OltAdmin;
use App\Models\OltOnt;
use App\Services\HuaweiSnmpReader;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

    /**
     * Una medición se considera al día durante 20 minutos (la revisión de
     * alertas mide cada 15), y se conserva unas horas para tener qué mostrar
     * mientras se mide de nuevo.
     */
    private const AL_DIA = 1200;
    private const CONSERVAR = 6 * 3600;

    /** Lo máximo que puede tardar un barrido antes de darlo por colgado. */
    private const MAXIMO_BARRIDO = 300;

    /**
     * Para la pantalla: nunca espera el barrido.
     *
     * En una OLT con cientos de ONT la tabla óptica tarda más de un minuto (la
     * OLT le pregunta a cada ONT) y la petición web se cortaba con 504. Se
     * devuelve la última medición guardada con su hora.
     *
     * Sólo se mide si el operador lo pide («Medir ahora», $refrescar): abrir
     * una pantalla ya no dispara un barrido. La revisión de alertas de cada 15
     * minutos (medirSiHaceFalta) sigue midiendo por su cuenta y deja guardada
     * la medición que se muestra acá.
     *
     * @return array<string,mixed>
     */
    public static function de(OltAdmin $olt, bool $refrescar = false): array
    {
        $guardado = Cache::get(self::clave($olt));

        $midiendo = self::midiendo($olt);

        if ($refrescar) {
            $midiendo = self::lanzar($olt) || $midiendo;
        }

        $estado = ['desde_cache' => true, 'midiendo' => $midiendo, 'al_dia' => is_array($guardado) && !self::vieja($guardado)];

        if (is_array($guardado)) {
            return $guardado + $estado;
        }

        return self::vacio($olt) + array_merge($estado, ['desde_cache' => false]);
    }

    /**
     * Cancela la medición pedida desde la pantalla. El barrido SNMP que esté
     * corriendo no se puede cortar a mitad, pero no sigue con el próximo, no
     * guarda lo que midió y suelta el candado. La de la revisión de alertas no
     * se toca: sin ella se cerrarían las alertas de señal abiertas.
     *
     * @return bool si había una medición en curso
     */
    public static function cancelar(OltAdmin $olt): bool
    {
        if (!self::midiendo($olt)) {
            return false;
        }

        Cache::put(self::claveCancelada($olt), now()->toIso8601String(), self::MAXIMO_BARRIDO);
        Cache::forget("olt:{$olt->id}:senal:lanzada");

        return true;
    }

    /** Para procesos de fondo (revisión de alertas): la guardada si está al día, si no mide. */
    public static function medirSiHaceFalta(OltAdmin $olt): array
    {
        $guardado = Cache::get(self::clave($olt));

        if (is_array($guardado) && !self::vieja($guardado)) {
            return $guardado;
        }

        return self::medirAhora($olt);
    }

    /**
     * Mide ya y guarda. Si otro proceso está midiendo esta OLT, no repite el barrido.
     *
     * @param bool $cancelable la lanzada desde la pantalla (olt:medir-senal): el
     *                         operador la puede cancelar. La de alertas, no.
     */
    public static function medirAhora(OltAdmin $olt, bool $cancelable = false): array
    {
        $candado = Cache::lock("olt:{$olt->id}:senal:midiendo", self::MAXIMO_BARRIDO);

        if (!$candado->get()) {
            $guardado = Cache::get(self::clave($olt));

            return is_array($guardado) ? $guardado : self::vacio($olt);
        }

        $cancelada = fn () => $cancelable && Cache::has(self::claveCancelada($olt));

        try {
            $resultado = self::leer($olt, $cancelada);

            if ($cancelada()) {
                return array_merge(self::vacio($olt), ['error' => 'Medición cancelada por el usuario.', 'cancelada' => true]);
            }

            if (($resultado['onts'] ?? []) !== []) {
                Cache::put(self::clave($olt), $resultado, now()->addSeconds(self::CONSERVAR));
                self::guardarEstado($olt, $resultado['onts']);
            }

            return $resultado;
        } finally {
            $candado->release();
            Cache::forget("olt:{$olt->id}:senal:lanzada");

            if ($cancelable) {
                Cache::forget(self::claveCancelada($olt));
            }
        }
    }

    /**
     * Guarda en la ficha de cada ONT si está prendida o apagada.
     *
     * Hasta ahora «olt_onts.status» no era un estado: lo escribía una sola vez
     * registerONT() con «offline» al autorizar el equipo, y sólo volvía a
     * «online» si alguien apretaba «Activar» a mano. Nadie leía nunca el
     * estado real de la OLT. Por eso Waonet mostraba 70 de 173 apagadas
     * cuando en la calle estaban funcionando: no estaban caídas, estaban sin
     * releer desde el día que se dieron de alta.
     *
     * El dato bueno ya pasaba por acá en cada barrido y se tiraba. Se guarda
     * de a grupos para no hacer una consulta por equipo.
     *
     * @param list<array<string,mixed>> $onts
     */
    private static function guardarEstado(OltAdmin $olt, array $onts): void
    {
        $porEstado = [];

        foreach ($onts as $ont) {
            $estado = $ont['status'] ?? null;

            // Sin estado no se toca nada: es preferible dejar lo de antes que
            // marcar como apagado un equipo que la OLT no supo contestar.
            if (!in_array($estado, ['online', 'offline'], true) || !isset($ont['fsp'], $ont['ont_id'])) {
                continue;
            }

            $porEstado[$estado][] = $ont['fsp'] . ':' . (int) $ont['ont_id'];
        }

        foreach ($porEstado as $estado => $claves) {
            foreach (array_chunk($claves, 300) as $tanda) {
                try {
                    OltOnt::where('olt_id', $olt->id)
                        ->whereIn(DB::raw("CONCAT(fsp, ':', ont_id)"), $tanda)
                        ->where('status', '!=', $estado)
                        ->update(['status' => $estado, 'updated_at' => now()]);
                } catch (\Throwable $e) {
                    Log::warning('[OLT] No se pudo guardar el estado de las ONT', [
                        'olt' => $olt->id, 'estado' => $estado, 'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    private static function claveCancelada(OltAdmin $olt): string
    {
        return "olt:{$olt->id}:senal:cancelada";
    }

    /** Arranca la medición en otro proceso, salvo que ya haya una en curso. */
    private static function lanzar(OltAdmin $olt): bool
    {
        // La marca evita lanzar un proceso por cada pantalla que se abre
        // mientras el barrido está en curso.
        if (!Cache::add("olt:{$olt->id}:senal:lanzada", now()->toIso8601String(), self::MAXIMO_BARRIDO)) {
            return true;
        }

        // Una cancelación vieja no puede frenar la medición nueva.
        Cache::forget(self::claveCancelada($olt));

        try {
            $php = (new \Symfony\Component\Process\PhpExecutableFinder())->find() ?: 'php';

            exec(sprintf(
                'nohup %s %s olt:medir-senal %d >> %s 2>&1 &',
                escapeshellarg($php),
                escapeshellarg(base_path('artisan')),
                (int) $olt->id,
                escapeshellarg(storage_path('logs/senal-olt.log'))
            ));

            return true;
        } catch (\Throwable $e) {
            Cache::forget("olt:{$olt->id}:senal:lanzada");
            Log::warning('[OLT] No se pudo lanzar la medición de señal', ['olt' => $olt->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    private static function midiendo(OltAdmin $olt): bool
    {
        return Cache::has("olt:{$olt->id}:senal:lanzada");
    }

    private static function vieja(array $medicion): bool
    {
        return empty($medicion['medido_en'])
            || \Carbon\Carbon::parse($medicion['medido_en'])->lt(now()->subSeconds(self::AL_DIA));
    }

    private static function clave(OltAdmin $olt): string
    {
        return "olt:{$olt->id}:senal";
    }

    /** @return array<string,mixed> */
    private static function vacio(OltAdmin $olt): array
    {
        return [
            'olt_id'    => (int) $olt->id,
            'medido_en' => null,
            'onts'      => [],
            'resumen'   => self::resumen([]),
            'por_pon'   => [],
            'peores'    => [],
            'error'     => null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function leer(OltAdmin $olt, ?\Closure $cancelada = null): array
    {
        $cancelada ??= fn () => false;

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
            // ZTE: la potencia de cada ONU se pide por consola, un comando por
            // puerto (la C320 de skartelecon: 620 ONT en ~20 s). Su MIB no es
            // la de Huawei y por SNMP no devolvía nada.
            // ZTE: por SNMP con su MIB (620 ONT en ~4 s). Si el SNMP no
            // responde, por consola, un comando por puerto (~20 s).
            if (strtolower((string) $olt->brand) === 'zte') {
                try {
                    $zte = new SnmpZte(new HuaweiSnmpReader($olt));
                    $mediciones = $zte->senal();

                    if ($mediciones !== [] && !$cancelada()) {
                        $datos = [];

                        foreach ($zte->onts() as $ont) {
                            $datos[$ont['fsp'] . ':' . $ont['ont_id']] = $ont;
                        }

                        $onts = array_map(fn ($m) => array_merge($m, [
                            'serial'      => $datos[$m['fsp'] . ':' . $m['ont_id']]['serial'] ?? null,
                            'description' => $datos[$m['fsp'] . ':' . $m['ont_id']]['description'] ?? null,
                            'status'      => $datos[$m['fsp'] . ':' . $m['ont_id']]['status'] ?? null,
                            'estado'      => self::clasificar($m['potencia']),
                        ]), $mediciones);

                        return array_merge($vacio, [
                            'onts'    => $onts,
                            'resumen' => self::resumen($onts),
                            'por_pon' => self::porPon($onts),
                            'peores'  => self::peores($onts),
                        ]);
                    }
                } catch (\Throwable $e) {
                    Log::info('[OLT] ZTE sin SNMP, se mide por consola', ['olt' => $olt->id, 'error' => $e->getMessage()]);
                }

                return self::leerPorConsola($olt, $vacio, $cancelada);
            }

            $snmp = new HuaweiSnmpReader($olt);

            // Cada familia de equipos publica la señal en su propia MIB: Huawei
            // en la suya, las EPON con la NSCRTV y las GPON C-Data en la suya
            // propia. Se prueban las otras primero en las que no son Huawei,
            // para no gastarle un barrido de más a una Huawei con cientos de ONT.
            $otras = strtolower((string) $olt->brand) !== 'huawei'
                ? [new SnmpEponNscrtv($snmp), new SnmpGponCdata($snmp)]
                : [];

            $mediciones = null;
            $lista      = null;

            // Entre un barrido y el siguiente se mira si el operador canceló:
            // uno que ya empezó no se puede cortar, pero no se larga el otro.
            foreach ($otras as $lector) {
                if ($cancelada()) {
                    return $vacio;
                }

                if ($lector->esCompatible()) {
                    $mediciones = $cancelada() ? [] : $lector->senal();
                    $lista      = $cancelada() ? [] : $lector->onts();
                    break;
                }
            }

            if ($cancelada()) {
                return $vacio;
            }

            if ($mediciones === null) {
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

            if ($cancelada()) {
                return $vacio;
            }

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

    /** La señal por consola, puerto por puerto (OLT ZTE). */
    private static function leerPorConsola(OltAdmin $olt, array $vacio, \Closure $cancelada): array
    {
        $despachador = app(\App\Services\OltTelnetDispatcher::class);

        // El nombre y el serial: de la lista guardada; si no hay, de la OLT.
        $lista = \App\Models\OltOnt::where('olt_id', $olt->id)->get(['fsp', 'ont_id', 'serial', 'description', 'status'])->toArray()
            ?: (array) $despachador->dispatch((int) $olt->id, 'getAuthorizedONTs', []);

        $datos = [];

        foreach ($lista as $ont) {
            $datos[$ont['fsp'] . ':' . $ont['ont_id']] = $ont;
        }

        $puertos = array_values(array_unique(array_column($lista, 'fsp')));
        natsort($puertos);
        $onts = [];

        foreach ($puertos as $puerto) {
            if ($cancelada()) {
                return $vacio;
            }

            foreach ((array) $despachador->dispatch((int) $olt->id, 'potenciasDelPuerto', ['fsp' => $puerto]) as $m) {
                $ont = $datos[$m['fsp'] . ':' . $m['ont_id']] ?? [];

                $onts[] = [
                    'fsp'         => $m['fsp'],
                    'ont_id'      => (int) $m['ont_id'],
                    'potencia'    => $m['potencia'],
                    'tx'          => null,
                    'corriente'   => null,
                    'voltaje'     => null,
                    'temperatura' => null,
                    'serial'      => $ont['serial']      ?? null,
                    'description' => $ont['description'] ?? null,
                    'status'      => $ont['status']      ?? null,
                    'estado'      => self::clasificar($m['potencia']),
                ];
            }
        }

        if (!$onts) {
            return array_merge($vacio, ['error' => 'La OLT no devolvió potencias por consola.']);
        }

        return array_merge($vacio, [
            'onts'    => $onts,
            'resumen' => self::resumen($onts),
            'por_pon' => self::porPon($onts),
            'peores'  => self::peores($onts),
        ]);
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
