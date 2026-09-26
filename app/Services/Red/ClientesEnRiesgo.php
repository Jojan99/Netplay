<?php

namespace App\Services\Red;

use Illuminate\Support\Facades\DB;

/**
 * Los clientes que necesitan una decisión, separados por cuál.
 *
 * Hoy se ven todos iguales: una lista de deudores y, aparte, una de señal
 * mala. Pero no son lo mismo y no se hace lo mismo con ellos.
 *
 * Un cliente cuyo equipo lleva días apagado Y debe plata no es cartera: ya se
 * fue y nadie se dio cuenta. Perseguirlo es tiempo perdido y la deuda que
 * sigue creciendo en el papel no la va a pagar nadie. En cambio, un cliente
 * apagado que está al día tiene una falla que no reportó, y va a llamar
 * enojado —o se va a ir callado—.
 *
 * La plataforma ya guarda todo esto todos los días. Sólo no lo decía.
 */
class ClientesEnRiesgo
{
    /** Cuántos días atrás se mira. Menos de esto es ruido de un corte de luz. */
    private const DIAS = 7;

    /** Apagado más que esto es «no está usando el servicio». */
    private const APAGADO_ALTO = 85;

    private const APAGADO_MEDIO = 50;

    /** Tantas caídas en la semana es un equipo inestable, no un apagón. */
    private const CAIDAS = 3;

    /** Debajo de esto la óptica trabaja al filo. */
    private const AL_BORDE = SaludDeLaRed::AL_BORDE;

    /**
     * @return array{grupos: array<string,array<string,mixed>>, medido_desde: ?string}
     */
    public static function de(int $companyId): array
    {
        $desde = now()->subDays(self::DIAS)->toDateString();

        $red = DB::table('red_equipo_dia')
            ->where('company_id', $companyId)
            ->where('fecha', '>=', $desde)
            ->whereNotNull('user_id')
            ->select(
                'user_id',
                DB::raw('MAX(descripcion) descripcion'),
                DB::raw('MAX(serial) serial'),
                DB::raw('MAX(fsp) fsp'),
                DB::raw('MAX(ont_id) ont_id'),
                DB::raw('SUM(muestras) muestras'),
                DB::raw('SUM(muestras_offline) apagadas'),
                DB::raw('SUM(caidas) caidas'),
                DB::raw('MIN(rx_min) rx_min'),
                DB::raw('SUM(rx_suma) rx_suma'),
                DB::raw('SUM(rx_muestras) rx_muestras'),
                DB::raw('COUNT(*) dias'),
                DB::raw('SUM(CASE WHEN rx_suma / NULLIF(rx_muestras, 0) < ' . self::AL_BORDE . ' THEN 1 ELSE 0 END) dias_bajos'),
            )
            ->groupBy('user_id')
            ->get();

        if ($red->isEmpty()) {
            return ['grupos' => self::vacios(), 'medido_desde' => null];
        }

        $deudas = self::deudas($companyId, $red->pluck('user_id')->all());
        // Cómo está el enlace AHORA: al que ya le arreglaron la señal no tiene
        // sentido seguir mostrándolo porque el promedio de la semana arrastra
        // los días malos.
        $ahora = SaludDeLaRed::potenciaDeAhora($companyId);
        $fichas = DB::table('user_data')->whereIn('user_id', $red->pluck('user_id'))
            ->get(['user_id', 'names', 'lastname', 'dni', 'phone', 'address'])
            ->keyBy('user_id');

        $grupos = self::vacios();

        foreach ($red as $r) {
            $muestras = max(1, (int) $r->muestras);
            $apagado  = (int) round((int) $r->apagadas * 100 / $muestras);
            $deuda    = (float) ($deudas[$r->user_id]['monto'] ?? 0);
            $ficha    = $fichas[$r->user_id] ?? null;
            $rxProm   = (int) $r->rx_muestras > 0 ? (float) $r->rx_suma / (int) $r->rx_muestras : null;
            $rxAhora  = $ahora[$r->user_id]['rx'] ?? null;

            $fila = [
                'user_id'    => (int) $r->user_id,
                'nombre'     => $ficha ? trim(($ficha->names ?? '') . ' ' . ($ficha->lastname ?? '')) : ($r->descripcion ?: '—'),
                'cedula'     => $ficha->dni ?? null,
                'telefono'   => $ficha->phone ?? null,
                'direccion'  => $ficha->address ?? null,
                'serial'     => $r->serial,
                'fsp'        => $r->fsp,
                'ont_id'     => $r->ont_id !== null ? (int) $r->ont_id : null,
                'apagado'    => $apagado,
                'caidas'     => (int) $r->caidas,
                'rx_min'     => $r->rx_min !== null ? round((float) $r->rx_min, 1) : null,
                'rx_prom'    => $rxProm !== null ? round($rxProm, 1) : null,
                'rx_ahora'   => $rxAhora !== null ? round($rxAhora, 1) : null,
                'dias_bajos' => (int) $r->dias_bajos,
                'dias'       => (int) $r->dias,
                'deuda'      => $deuda,
                'facturas'   => (int) ($deudas[$r->user_id]['facturas'] ?? 0),
            ];

            // El orden importa: cada cliente cae en UN grupo, el más grave, y
            // así la lista no se repite ni hay que decidir dos veces.
            $grupo = match (true) {
                $apagado >= self::APAGADO_ALTO && $deuda > 0   => 'se_fue',
                $apagado >= self::APAGADO_MEDIO && $deuda <= 0 => 'falla_sin_reportar',
                (int) $r->caidas >= self::CAIDAS               => 'inestable',
                self::vaAQuedarseSinLuz($rxProm, $rxAhora, (int) $r->dias_bajos, (int) $r->dias) => 'senal_al_borde',
                default => null,
            };

            if ($grupo) {
                $grupos[$grupo]['clientes'][] = $fila;
            }
        }

        // Cada grupo por lo que más duele primero.
        $orden = [
            'se_fue'             => fn ($a, $b) => $b['deuda'] <=> $a['deuda'],
            'falla_sin_reportar' => fn ($a, $b) => $b['apagado'] <=> $a['apagado'],
            'inestable'          => fn ($a, $b) => $b['caidas'] <=> $a['caidas'],
            'senal_al_borde'     => fn ($a, $b) => ($a['rx_prom'] ?? 0) <=> ($b['rx_prom'] ?? 0),
        ];

        foreach ($grupos as $k => &$g) {
            usort($g['clientes'], $orden[$k]);
            $g['cuantos'] = count($g['clientes']);
            $g['deuda']   = array_sum(array_column($g['clientes'], 'deuda'));
        }

        return ['grupos' => $grupos, 'medido_desde' => $desde];
    }

    /**
     * Si la señal de este cliente es un problema o fue un mal día.
     *
     * Se mira el promedio de la semana, no la peor lectura: la peor es una
     * sola medición entre unas trescientas, y con eso solo entraban a la lista
     * treinta y un clientes que en realidad pasan la semana en -22 dBm.
     *
     * Y se agrega la otra mitad de la verdad: un equipo que promedia -25 pero
     * se va abajo del límite todos los días también está al filo, aunque el
     * promedio lo disimule. Por eso cuenta si le pasó la mitad de los días
     * medidos o más.
     */
    private static function vaAQuedarseSinLuz(?float $promedio, ?float $ahora, int $diasBajos, int $dias): bool
    {
        if ($promedio === null) {
            return false;
        }

        // Si la última medición ya está bien, se arregló: la semana mala es
        // historia y no hay a quién mandar. Sin esto el cliente seguía en la
        // lista días después de la visita y la pantalla parecía congelada.
        if ($ahora !== null && $ahora >= self::AL_BORDE) {
            return false;
        }

        return $promedio < self::AL_BORDE || ($dias > 0 && $diasBajos * 2 >= $dias);
    }

    /** @return array<string,array<string,mixed>> */
    private static function vacios(): array
    {
        return [
            'se_fue' => [
                'titulo'  => 'Ya no están',
                'que_es'  => 'Llevan días con el equipo apagado y deben plata. No es cartera: se fueron y la deuda sigue creciendo en el papel.',
                'que_hacer' => 'Confirme y dales de baja. Perseguir este cobro es tiempo perdido.',
                'tono'    => 'danger',
                'clientes' => [], 'cuantos' => 0, 'deuda' => 0,
            ],
            'falla_sin_reportar' => [
                'titulo'  => 'Falla que nadie reportó',
                'que_es'  => 'Están al día pero su equipo pasa apagado. Tienen un problema y no lo dijeron.',
                'que_hacer' => 'Llamalos antes de que llamen ellos. Es el que se va callado.',
                'tono'    => 'warn',
                'clientes' => [], 'cuantos' => 0, 'deuda' => 0,
            ],
            'inestable' => [
                'titulo'  => 'Se cae y vuelve',
                'que_es'  => 'El equipo se desconecta varias veces por semana. El cliente lo nota aunque no llame.',
                'que_hacer' => 'Revise acometida, roseta y corriente. Suele ser el cable de la casa.',
                'tono'    => 'warn',
                'clientes' => [], 'cuantos' => 0, 'deuda' => 0,
            ],
            'senal_al_borde' => [
                'titulo'  => 'Señal al borde',
                'que_es'  => 'Reciben menos luz de la que deberían. Todavía andan, pero van a fallar.',
                'que_hacer' => 'Visita preventiva: conector sucio, curva forzada o empalme flojo.',
                'tono'    => 'info',
                'clientes' => [], 'cuantos' => 0, 'deuda' => 0,
            ],
        ];
    }

    /** @param list<int> $userIds @return array<int,array{monto:float,facturas:int}> */
    private static function deudas(int $companyId, array $userIds): array
    {
        if (!$userIds) {
            return [];
        }

        $filas = \App\Models\DetFacturation::join('cab_facturations as cab', 'cab.id', '=', 'det_facturations.cab_id')
            ->where('cab.company_id', $companyId)
            ->whereIn('cab.user_id', $userIds)
            ->where('det_facturations.paid', 0)
            ->select('cab.user_id', 'det_facturations.*')
            ->get();

        $out = [];

        foreach ($filas as $f) {
            $saldo = $f->outstanding();

            if ($saldo <= 0) {
                continue;
            }

            $out[$f->user_id]['monto']    = ($out[$f->user_id]['monto'] ?? 0) + $saldo;
            $out[$f->user_id]['facturas'] = ($out[$f->user_id]['facturas'] ?? 0) + 1;
        }

        return $out;
    }
}
