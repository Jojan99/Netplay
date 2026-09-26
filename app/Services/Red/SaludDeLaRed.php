<?php

namespace App\Services\Red;

use App\Models\OltAdmin;
use App\Services\Olt\SenalDeLaOlt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * La historia de la red, para enterarse antes que el cliente.
 *
 * La señal ya se mide cada 15 minutos para las alertas, pero se olvidaba: no
 * había forma de ver que un puerto se viene cayendo de a poco (0/0/3 con la
 * mediana en -25 dBm y 17 clientes al borde) ni quién se reinicia todos los
 * días. Aquí esa misma medición queda guardada —resumida, no lectura por
 * lectura— y se convierte en tres respuestas:
 *
 *   1. Qué puertos hay que ir a revisar (y si empeoraron esta semana).
 *   2. Qué clientes están al filo de quedarse sin servicio.
 *   3. Qué equipos se apagan y prenden todo el tiempo.
 *
 * No consulta la OLT: usa lo que el barrido de alertas ya dejó medido.
 */
class SaludDeLaRed
{
    /** Debajo de esto el equipo trabaja al borde de lo que su óptica ve. */
    public const AL_BORDE = -26.0;

    /** Lo que se conserva: suficiente para comparar semanas sin engordar la base. */
    private const DIAS_MUESTRAS = 90;
    private const DIAS_EQUIPOS = 400;

    /** Una medición más vieja que esto ya no dice nada del ahora. */
    private const MINUTOS_UTIL = 40;

    /** Guarda la última medición de cada OLT. Devuelve cuántos puertos anotó. */
    public static function muestrear(): int
    {
        $puertos = 0;

        foreach (OltAdmin::query()->get() as $olt) {
            try {
                $puertos += self::guardarDeUnaOlt($olt);
            } catch (\Throwable $e) {
                Log::warning('[SaludDeLaRed] No se pudo guardar la medición', ['olt' => $olt->id, 'error' => $e->getMessage()]);
            }
        }

        return $puertos;
    }

    private static function guardarDeUnaOlt(OltAdmin $olt): int
    {
        $medicion = SenalDeLaOlt::de($olt);
        $onts = $medicion['onts'] ?? [];
        $medidoEn = $medicion['medido_en'] ?? null;

        if (!$onts || !$medidoEn) {
            return 0;
        }

        $momento = \Carbon\Carbon::parse($medidoEn);

        if ($momento->lt(now()->subMinutes(self::MINUTOS_UTIL))) {
            return 0;   // vieja: la de esta vuelta todavía no llegó
        }

        // Ya guardada (el barrido es cada 15 min y esto corre igual de seguido).
        if (DB::table('red_muestras')->where('olt_id', $olt->id)->where('medido_en', $momento->toDateTimeString())->exists()) {
            return 0;
        }

        $puertos = 0;

        // Quién tiene cliente y quién no. Un equipo apagado SIN cliente no es
        // una caída: es un alta vieja, un equipo reemplazado o alguien que se
        // fue. Contarlo como caída daba 40% de red abajo en Waonet cuando los
        // clientes sin servicio eran uno.
        $conCliente = DB::table('olt_onts')->where('olt_id', $olt->id)
            ->whereNotNull('user_data_id')
            ->pluck('ont_id', DB::raw("CONCAT(fsp, ':', ont_id)"))
            ->keys()->flip();

        foreach (collect($onts)->groupBy('fsp') as $fsp => $delPuerto) {
            $rx = $delPuerto->pluck('potencia')->filter(fn ($p) => $p !== null)->map(fn ($p) => (float) $p)->sort()->values();

            $apagadas = $delPuerto->where('status', 'offline');
            $sinCliente = $apagadas->filter(fn ($o) => !isset($conCliente[$o['fsp'] . ':' . (int) $o['ont_id']]))->count();

            // «offline» pasa a significar lo que la gente entiende: clientes
            // que deberían estar navegando y no lo están.
            $offline = $apagadas->count() - $sinCliente;

            DB::table('red_muestras')->insert([
                'company_id' => $olt->company_id,
                'olt_id'     => $olt->id,
                'fsp'        => (string) $fsp,
                'medido_en'  => $momento->toDateTimeString(),
                'onts'       => $delPuerto->count(),
                'online'     => $delPuerto->count() - $offline,
                'offline'    => $offline,
                'sin_cliente' => $sinCliente,
                'al_borde'   => $rx->filter(fn ($p) => $p < self::AL_BORDE)->count(),
                'rx_mediana' => $rx->isEmpty() ? null : $rx[intdiv($rx->count(), 2)],
                'rx_min'     => $rx->min(),
                'rx_max'     => $rx->max(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $puertos++;
        }

        self::guardarEquipos($olt, $onts, $momento);

        return $puertos;
    }

    /** El resumen del día de cada equipo: su peor señal, cuánto estuvo caído y cuántas veces se cayó. */
    private static function guardarEquipos(OltAdmin $olt, array $onts, \Carbon\Carbon $momento): void
    {
        $fecha = $momento->toDateString();

        $clientes = DB::table('olt_onts')->where('olt_id', $olt->id)
            ->get(['fsp', 'ont_id', 'user_data_id'])
            ->keyBy(fn ($o) => $o->fsp . ':' . $o->ont_id);

        foreach ($onts as $ont) {
            $clave = ['olt_id' => $olt->id, 'fsp' => (string) $ont['fsp'], 'ont_id' => (int) $ont['ont_id'], 'fecha' => $fecha];
            $fila = DB::table('red_equipo_dia')->where($clave)->first();
            $apagada = ($ont['status'] ?? null) === 'offline';
            $rx = $ont['potencia'] !== null ? (float) $ont['potencia'] : null;

            // Una caída es pasar de encendida a apagada entre dos mediciones.
            $cayo = $apagada && $fila && !(int) $fila->ultima_apagada;

            $datos = [
                'company_id'       => $olt->company_id,
                'user_id'          => $clientes[$ont['fsp'] . ':' . $ont['ont_id']]->user_data_id ?? null,
                'serial'           => $ont['serial'] ?? null,
                'descripcion'      => mb_substr((string) ($ont['description'] ?? ''), 0, 120) ?: null,
                'muestras'         => ($fila->muestras ?? 0) + 1,
                'muestras_offline' => ($fila->muestras_offline ?? 0) + ($apagada ? 1 : 0),
                'caidas'           => ($fila->caidas ?? 0) + ($cayo ? 1 : 0),
                'ultima_apagada'   => $apagada,
                'updated_at'       => now(),
            ];

            if ($rx !== null) {
                $datos += [
                    'rx_min'      => min($rx, $fila->rx_min ?? $rx),
                    'rx_max'      => max($rx, $fila->rx_max ?? $rx),
                    'rx_suma'     => ($fila->rx_suma ?? 0) + $rx,
                    'rx_muestras' => ($fila->rx_muestras ?? 0) + 1,
                ];
            }

            $fila
                ? DB::table('red_equipo_dia')->where($clave)->update($datos)
                : DB::table('red_equipo_dia')->insert($clave + $datos + ['created_at' => now()]);
        }
    }

    /**
     * Lo que hay que mirar hoy, ya masticado para la pantalla.
     *
     * @return array<string,mixed>
     */
    public static function resumen(int $companyId): array
    {
        $hoy = now('America/Bogota')->toDateString();

        return [
            'puertos'    => self::puertos($companyId),
            'al_borde'   => self::clientesAlBorde($companyId, $hoy),
            'inestables' => self::equiposInestables($companyId, $hoy),
            'medido_en'  => DB::table('red_muestras')->where('company_id', $companyId)->max('medido_en'),
            'dias'       => (int) DB::table('red_muestras')->where('company_id', $companyId)
                ->selectRaw('COUNT(DISTINCT DATE(medido_en)) d')->value('d'),
            'limite'     => self::AL_BORDE,
        ];
    }

    /**
     * La potencia que tiene cada cliente AHORA, según la última medición.
     *
     * Hace falta porque el resumen del día es un promedio: si a las diez de la
     * mañana un técnico limpió el conector y el enlace pasó de -29 a -22 dBm,
     * el promedio del día sigue arrastrando las horas malas y el cliente
     * seguía apareciendo «al borde» hasta el día siguiente. Eso hacía que la
     * pantalla pareciera que no se actualiza.
     *
     * No consulta la OLT: usa la medición que el barrido ya dejó guardada (se
     * rehace cada 15 minutos).
     *
     * @return array<int,array{rx: ?float, medido_en: ?string}>
     */
    public static function potenciaDeAhora(int $companyId): array
    {
        $out = [];

        foreach (OltAdmin::query()->where('company_id', $companyId)->get() as $olt) {
            $medicion = \App\Services\Olt\SenalDeLaOlt::de($olt);

            foreach ($medicion['onts'] ?? [] as $ont) {
                $u = $ont['user_id'] ?? null;

                if ($u === null) {
                    continue;
                }

                // Un cliente con dos ONT se queda con la peor: es la que le
                // está dando problema.
                $rx = $ont['potencia'];

                if (!isset($out[$u]) || ($rx !== null && ($out[$u]['rx'] === null || $rx < $out[$u]['rx']))) {
                    $out[$u] = ['rx' => $rx, 'medido_en' => $medicion['medido_en'] ?? null];
                }
            }
        }

        return $out;
    }

    /** Cada puerto PON: cómo está hoy y cómo venía la semana pasada. */
    private static function puertos(int $companyId): array
    {
        $ahora = DB::table('red_muestras as m')
            ->join(DB::raw('(SELECT olt_id, fsp, MAX(medido_en) ultima FROM red_muestras WHERE company_id = ' . (int) $companyId . ' GROUP BY olt_id, fsp) u'),
                fn ($j) => $j->on('u.olt_id', 'm.olt_id')->on('u.fsp', 'm.fsp')->on('u.ultima', 'm.medido_en'))
            ->join('olt_admins as o', 'o.id', '=', 'm.olt_id')
            ->where('m.company_id', $companyId)
            ->get(['m.olt_id', 'o.name as olt', 'm.fsp', 'm.onts', 'm.online', 'm.offline', 'm.sin_cliente', 'm.al_borde', 'm.rx_mediana', 'm.rx_min', 'm.medido_en']);

        // La mediana de la semana pasada, para ver si el puerto viene cayendo.
        $antes = DB::table('red_muestras')->where('company_id', $companyId)
            ->whereBetween('medido_en', [now()->subDays(14), now()->subDays(7)])
            ->selectRaw('olt_id, fsp, AVG(rx_mediana) rx')
            ->groupBy('olt_id', 'fsp')->get()->keyBy(fn ($x) => $x->olt_id . ':' . $x->fsp);

        return $ahora->map(function ($p) use ($antes) {
            $previa = $antes[$p->olt_id . ':' . $p->fsp]->rx ?? null;

            return [
                'olt_id'     => (int) $p->olt_id,
                'olt'        => $p->olt,
                'fsp'        => $p->fsp,
                'onts'       => (int) $p->onts,
                'offline'    => (int) $p->offline,
                'sin_cliente' => (int) ($p->sin_cliente ?? 0),
                'al_borde'   => (int) $p->al_borde,
                'rx_mediana' => $p->rx_mediana !== null ? (float) $p->rx_mediana : null,
                'rx_min'     => $p->rx_min !== null ? (float) $p->rx_min : null,
                'cambio'     => $previa !== null && $p->rx_mediana !== null ? round((float) $p->rx_mediana - (float) $previa, 2) : null,
                'medido_en'  => $p->medido_en,
            ];
        })->sortBy([['al_borde', 'desc'], ['rx_mediana', 'asc']])->values()->all();
    }

    /**
     * Clientes que hoy trabajaron por debajo del límite: los próximos en caerse.
     *
     * Se mira el PROMEDIO del día, no la peor lectura. La peor es una sola
     * medición de las ~50 que se toman: bastaba un valor raro para meter aquí a
     * un cliente que pasó el día en -22 dBm y mandar a un técnico a revisar un
     * enlace sano. La peor sigue a la vista, al lado, como dato.
     */
    private static function clientesAlBorde(int $companyId, string $fecha): array
    {
        $filas = DB::table('red_equipo_dia as e')
            ->leftJoin('user_data as u', 'u.user_id', '=', 'e.user_id')
            ->join('olt_admins as o', 'o.id', '=', 'e.olt_id')
            ->where('e.company_id', $companyId)->where('e.fecha', $fecha)
            ->where('e.rx_muestras', '>', 0)
            ->whereRaw('e.rx_suma / e.rx_muestras < ?', [self::AL_BORDE])
            ->orderByRaw('e.rx_suma / e.rx_muestras')
            ->limit(80)
            ->get([
                'e.olt_id', 'o.name as olt', 'e.fsp', 'e.ont_id', 'e.user_id', 'e.serial', 'e.descripcion',
                'e.rx_min', 'e.rx_max', 'e.muestras_offline', 'e.caidas',
                DB::raw('ROUND(e.rx_suma / NULLIF(e.rx_muestras, 0), 2) rx_prom'),
                DB::raw("TRIM(CONCAT(COALESCE(u.names, ''), ' ', COALESCE(u.lastname, ''))) cliente"),
                'u.phone',
            ])->map(fn ($x) => (array) $x)->all();

        // Se cruza con la última medición: al que ya le arreglaron el enlace
        // sale de la lista en el mismo momento, no al día siguiente.
        $ahora = self::potenciaDeAhora($companyId);

        $vivos = [];

        foreach ($filas as $fila) {
            $rx = $ahora[$fila['user_id']]['rx'] ?? null;

            if ($rx !== null && $rx >= self::AL_BORDE) {
                continue;   // ya está bien: no hay nada que ir a revisar
            }

            $fila['rx_ahora'] = $rx;
            $fila['medido_en'] = $ahora[$fila['user_id']]['medido_en'] ?? null;
            $vivos[] = $fila;
        }

        return $vivos;
    }

    /** Equipos que se apagan y prenden todo el día: casi siempre es la fibra o la energía. */
    private static function equiposInestables(int $companyId, string $fecha): array
    {
        return DB::table('red_equipo_dia as e')
            ->leftJoin('user_data as u', 'u.user_id', '=', 'e.user_id')
            ->join('olt_admins as o', 'o.id', '=', 'e.olt_id')
            ->where('e.company_id', $companyId)->where('e.fecha', $fecha)
            ->where('e.caidas', '>=', 2)
            ->orderByDesc('e.caidas')
            ->limit(30)
            ->get([
                'e.olt_id', 'o.name as olt', 'e.fsp', 'e.ont_id', 'e.user_id', 'e.descripcion', 'e.caidas',
                'e.muestras', 'e.muestras_offline', 'e.rx_min',
                DB::raw("TRIM(CONCAT(COALESCE(u.names, ''), ' ', COALESCE(u.lastname, ''))) cliente"),
            ])->map(fn ($x) => (array) $x)->all();
    }

    /** Lo viejo se borra: esto es para ver tendencias, no para guardar todo. */
    public static function limpiar(): int
    {
        return DB::table('red_muestras')->where('medido_en', '<', now()->subDays(self::DIAS_MUESTRAS))->delete()
            + DB::table('red_equipo_dia')->where('fecha', '<', now()->subDays(self::DIAS_EQUIPOS)->toDateString())->delete();
    }
}
