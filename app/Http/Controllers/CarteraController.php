<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Tablero de cartera y cobranza: quién debe, hace cuánto y qué se recupera.
 *
 * La fecha de una factura es la de emisión (no hay fecha de vencimiento): una
 * factura del mes en curso sin pagar es normal. Se considera en mora la que
 * lleva más de DIAS_PARA_MORA sin pagarse, es decir, un ciclo anterior sin
 * pagar. Los clientes retirados (user_data.active = 0) se cuentan aparte para
 * que su deuda vieja no tape la de la cartera activa.
 */
class CarteraController extends Controller
{
    /** Días desde la emisión a partir de los cuales una factura está en mora. */
    public const DIAS_PARA_MORA = 30;

    /** Saldo de una factura: total menos descuento y abonos. */
    private const SALDO = 'GREATEST(0, d.price_total - COALESCE(d.price_discount,0) - COALESCE(d.price_abone,0))';

    /** Días que se miran después de un recordatorio para ver si pagó. */
    private const DIAS_EFECTO_AVISO = 7;

    /** Filas por lista de deudores. */
    private const MAXIMO_FILAS = 200;

    /** GET api/cartera/resumen */
    public function resumen(): JsonResponse
    {
        $empresa = (int) getSessionCompanyId();
        $limite  = now()->subDays(self::DIAS_PARA_MORA)->toDateString();

        $clientes  = $this->clientesConDeuda($empresa, $limite);
        $activos   = array_values(array_filter($clientes, fn ($c) => !$c['retirado']));
        $enMora    = array_values(array_filter($activos, fn ($c) => $c['deuda_en_mora'] > 0));
        $retirados = array_values(array_filter($clientes, fn ($c) => $c['retirado']));

        return response()->json(['status' => 0, 'data' => [
            'dias_para_mora' => self::DIAS_PARA_MORA,
            'indicadores'    => $this->indicadores($empresa, $activos, $enMora, $retirados),
            'antiguedad'     => $this->antiguedad($enMora),
            'en_mora'        => array_slice($enMora, 0, self::MAXIMO_FILAS),
            'retirados'      => array_slice($retirados, 0, self::MAXIMO_FILAS),
            'recaudo_meses'  => $this->recaudoPorMes($empresa),
            'recordatorios'  => $this->efectoDeRecordatorios($empresa),
            'registro_avisos_desde' => DB::table('wa_avisos_enviados')->where('company_id', $empresa)->min('enviado_en'),
            'compromisos'    => DB::table('payment_commitments')->where('company_id', $empresa)
                ->selectRaw('status, COUNT(*) n, COALESCE(SUM(amount_committed),0) monto')
                ->groupBy('status')->get()->keyBy('status'),
            'suspensiones'   => DB::table('auto_suspend_logs')->where('company_id', $empresa)
                ->where('created_at', '>=', now()->subDays(30))
                ->selectRaw('action, COUNT(*) n')->groupBy('action')->pluck('n', 'action'),
            'calculado_en'   => now()->toIso8601String(),
        ]]);
    }

    /**
     * GET api/cartera/deudores — los que más deben, para el tablero.
     *
     * El resumen completo arma 200 filas con compromisos y avisos; el tablero
     * sólo necesita cinco nombres y su deuda, así que esto es una consulta
     * sola con LIMIT.
     */
    public function deudores(Request $request): JsonResponse
    {
        $empresa = (int) getSessionCompanyId();
        $limite  = now()->subDays(self::DIAS_PARA_MORA)->toDateString();
        $cuantos = min(20, max(1, (int) $request->query('limite', 5)));
        $saldo   = self::SALDO;

        $filas = DB::table('det_facturations as d')
            ->join('cab_facturations as c', 'c.id', '=', 'd.cab_id')
            ->join('user_data as ud', 'ud.user_id', '=', 'c.user_id')
            ->where('c.company_id', $empresa)
            ->where('d.paid', 0)
            ->where('ud.active', 1)
            ->groupBy('c.user_id', 'ud.names', 'ud.lastname')
            ->havingRaw("SUM(CASE WHEN d.date_facturation < ? THEN {$saldo} ELSE 0 END) > 0", [$limite])
            ->selectRaw("c.user_id,
                TRIM(CONCAT(COALESCE(ud.names,''), ' ', COALESCE(ud.lastname,''))) AS nombre,
                SUM(CASE WHEN d.date_facturation < ? THEN 1 ELSE 0 END) AS facturas,
                SUM(CASE WHEN d.date_facturation < ? THEN {$saldo} ELSE 0 END) AS deuda,
                MIN(d.date_facturation) AS mas_vieja", [$limite, $limite])
            ->orderByDesc('deuda')
            ->limit($cuantos)
            ->get();

        $hoy = now()->startOfDay();

        return standardApiReponse('OK', $filas->map(fn ($f) => [
            'user_id'  => (int) $f->user_id,
            'nombre'   => $f->nombre ?: "Cliente {$f->user_id}",
            'facturas' => (int) $f->facturas,
            'deuda'    => round((float) $f->deuda, 2),
            'dias'     => $f->mas_vieja ? (int) $hoy->diffInDays($f->mas_vieja) : 0,
        ])->all(), 0, JsonResponse::HTTP_OK);
    }

    /**
     * GET api/cartera/mora — la deuda repartida por antigüedad, para el tablero.
     *
     * Una consulta sola: cuánto se debe y cuántos clientes hay en cada tramo,
     * más lo que se recuperó este mes. El resumen completo hace mucho más y
     * tarda demasiado para una pantalla que se abre en cada ingreso.
     */
    public function mora(): JsonResponse
    {
        $empresa = (int) getSessionCompanyId();
        $saldo   = self::SALDO;
        $hoy     = now()->toDateString();

        $tramos = DB::table('det_facturations as d')
            ->join('cab_facturations as c', 'c.id', '=', 'd.cab_id')
            ->join('user_data as ud', 'ud.user_id', '=', 'c.user_id')
            ->where('c.company_id', $empresa)
            ->where('d.paid', 0)
            ->where('ud.active', 1)
            ->whereRaw('DATEDIFF(?, d.date_facturation) > ?', [$hoy, self::DIAS_PARA_MORA])
            ->selectRaw("
                CASE
                    WHEN DATEDIFF(?, d.date_facturation) <= 60  THEN '1 a 30 días'
                    WHEN DATEDIFF(?, d.date_facturation) <= 90  THEN '31 a 60 días'
                    WHEN DATEDIFF(?, d.date_facturation) <= 120 THEN '61 a 90 días'
                    ELSE 'más de 90 días'
                END AS tramo,
                SUM({$saldo}) AS deuda,
                COUNT(DISTINCT c.user_id) AS clientes", [$hoy, $hoy, $hoy])
            ->groupBy('tramo')
            ->get()->keyBy('tramo');

        $orden = ['1 a 30 días', '31 a 60 días', '61 a 90 días', 'más de 90 días'];

        $antiguedad = array_map(fn ($t) => [
            'tramo'    => $t,
            'deuda'    => round((float) ($tramos[$t]->deuda ?? 0), 2),
            'clientes' => (int) ($tramos[$t]->clientes ?? 0),
        ], $orden);

        $activos = DB::table('user_data as ud')->join('users as u', 'u.id', '=', 'ud.user_id')
            ->where('u.company_id', $empresa)->where('ud.active', 1)->count();

        $enMora = DB::table('det_facturations as d')
            ->join('cab_facturations as c', 'c.id', '=', 'd.cab_id')
            ->join('user_data as ud', 'ud.user_id', '=', 'c.user_id')
            ->where('c.company_id', $empresa)->where('d.paid', 0)->where('ud.active', 1)
            ->whereRaw('DATEDIFF(?, d.date_facturation) > ?', [$hoy, self::DIAS_PARA_MORA])
            ->distinct()->count('c.user_id');

        $recuperado = (float) DB::table('payment_logs as p')
            ->join('det_facturations as d', 'd.id', '=', 'p.det_facturation_id')
            ->where('p.company_id', $empresa)
            ->where('p.created_at', '>=', now()->startOfMonth())
            ->whereRaw('DATEDIFF(DATE(p.created_at), d.date_facturation) > ?', [self::DIAS_PARA_MORA])
            ->sum('p.amount');

        return standardApiReponse('OK', [
            'antiguedad'  => $antiguedad,
            'total'       => round(array_sum(array_column($antiguedad, 'deuda')), 2),
            'clientes'    => $enMora,
            'al_dia_pct'  => $activos ? round(max(0, $activos - $enMora) * 100 / $activos, 1) : null,
            'recuperado'  => round($recuperado, 2),
        ], 0, JsonResponse::HTTP_OK);
    }

    // ── Cálculos ──────────────────────────────────────────────────────────

    /** Clientes con saldo pendiente, ordenados por deuda en mora y luego total. */
    private function clientesConDeuda(int $empresa, string $limite): array
    {
        $saldo = self::SALDO;

        $filas = DB::table('det_facturations as d')
            ->join('cab_facturations as c', 'c.id', '=', 'd.cab_id')
            ->join('user_data as ud', 'ud.user_id', '=', 'c.user_id')
            ->leftJoin('internet_status as ist', 'ist.id', '=', 'ud.status_internet_id')
            ->where('c.company_id', $empresa)
            ->where('d.paid', 0)
            ->groupBy('c.user_id', 'ud.names', 'ud.lastname', 'ud.dni', 'ud.phone', 'ud.active', 'ist.name')
            ->havingRaw("SUM({$saldo}) > 0")
            ->selectRaw("c.user_id, ud.active,
                TRIM(CONCAT(COALESCE(ud.names,''), ' ', COALESCE(ud.lastname,''))) AS nombre,
                ud.dni AS documento, ud.phone AS telefono, ist.name AS estado_servicio,
                SUM(CASE WHEN d.date_facturation < ? THEN 1 ELSE 0 END) AS facturas_en_mora,
                SUM(CASE WHEN d.date_facturation < ? THEN {$saldo} ELSE 0 END) AS deuda_en_mora,
                SUM({$saldo}) AS deuda_total,
                MIN(d.date_facturation) AS factura_mas_vieja", [$limite, $limite])
            ->orderByDesc('deuda_en_mora')
            ->orderByDesc('deuda_total')
            ->get();

        if ($filas->isEmpty()) {
            return [];
        }

        $ids = $filas->pluck('user_id')->all();

        $compromisos = DB::table('payment_commitments')->where('company_id', $empresa)
            ->where('status', 'pending')->whereIn('user_id', $ids)
            ->pluck('commitment_date', 'user_id');

        $avisos = DB::table('wa_avisos_enviados')->where('company_id', $empresa)
            ->whereIn('user_id', $ids)
            ->groupBy('user_id')->selectRaw('user_id, MAX(enviado_en) ultimo')
            ->pluck('ultimo', 'user_id');

        $hoy = now()->startOfDay();

        return $filas->map(fn ($f) => [
            'user_id'          => (int) $f->user_id,
            'nombre'           => $f->nombre ?: "Cliente {$f->user_id}",
            'documento'        => $f->documento,
            'telefono'         => $f->telefono,
            'estado_servicio'  => $f->estado_servicio,
            'suspendido'       => !str_starts_with(strtoupper((string) $f->estado_servicio), 'ACT'),
            'retirado'         => (int) $f->active !== 1,
            'facturas_en_mora' => (int) $f->facturas_en_mora,
            'deuda_en_mora'    => round((float) $f->deuda_en_mora, 2),
            'deuda_total'      => round((float) $f->deuda_total, 2),
            'factura_mas_vieja' => $f->factura_mas_vieja,
            'dias'             => $f->factura_mas_vieja ? (int) $hoy->diffInDays($f->factura_mas_vieja) : 0,
            'compromiso'       => $compromisos[$f->user_id] ?? null,
            'ultimo_aviso'     => $avisos[$f->user_id] ?? null,
        ])->values()->all();
    }

    private function indicadores(int $empresa, array $activosConDeuda, array $enMora, array $retirados): array
    {
        $activos = DB::table('user_data as ud')
            ->join('users as u', 'u.id', '=', 'ud.user_id')
            ->where('u.company_id', $empresa)
            ->where('ud.active', 1)
            ->count();

        $inicioMes = now()->startOfMonth();
        $dias = self::DIAS_PARA_MORA;

        $recaudadoMes = (float) DB::table('payment_logs')->where('company_id', $empresa)
            ->where('created_at', '>=', $inicioMes)->sum('amount');

        // Lo cobrado este mes de facturas que al pagarse ya estaban en mora.
        $recuperadoMes = (float) DB::table('payment_logs as p')
            ->join('det_facturations as d', 'd.id', '=', 'p.det_facturation_id')
            ->where('p.company_id', $empresa)
            ->where('p.created_at', '>=', $inicioMes)
            ->whereRaw('DATEDIFF(DATE(p.created_at), d.date_facturation) > ?', [$dias])
            ->sum('p.amount');

        $pendienteDelMes = array_sum(array_map(fn ($c) => $c['deuda_total'] - $c['deuda_en_mora'], $activosConDeuda));

        return [
            'cartera_en_mora'     => round(array_sum(array_column($enMora, 'deuda_en_mora')), 2),
            'clientes_en_mora'    => count($enMora),
            'clientes_activos'    => $activos,
            'porcentaje_al_dia'   => $activos ? round(max(0, $activos - count($enMora)) * 100 / $activos, 1) : null,
            'pendiente_del_mes'   => round($pendienteDelMes, 2),
            'recaudado_mes'       => round($recaudadoMes, 2),
            'recuperado_mes'      => round($recuperadoMes, 2),
            'suspendidos_en_mora' => count(array_filter($enMora, fn ($c) => $c['suspendido'])),
            'retirados_con_deuda' => count($retirados),
            'deuda_retirados'     => round(array_sum(array_column($retirados, 'deuda_total')), 2),
        ];
    }

    /** Cartera en mora según la factura más vieja sin pagar de cada cliente. */
    private function antiguedad(array $enMora): array
    {
        $tramos = [
            '31–60 días'  => [31, 60],
            '61–90 días'  => [61, 90],
            '91–180 días' => [91, 180],
            'Más de 180'  => [181, PHP_INT_MAX],
        ];

        $salida = [];

        foreach ($tramos as $nombre => [$desde, $hasta]) {
            $del = array_filter($enMora, fn ($c) => $c['dias'] >= $desde && $c['dias'] <= $hasta);
            $salida[] = [
                'tramo'    => $nombre,
                'clientes' => count($del),
                'monto'    => round(array_sum(array_column($del, 'deuda_en_mora')), 2),
            ];
        }

        return $salida;
    }

    /** Recaudo de los últimos 6 meses, con la parte que vino de facturas en mora. */
    private function recaudoPorMes(int $empresa): array
    {
        $porMes = DB::table('payment_logs as p')
            ->leftJoin('det_facturations as d', 'd.id', '=', 'p.det_facturation_id')
            ->where('p.company_id', $empresa)
            ->where('p.created_at', '>=', now()->subMonths(5)->startOfMonth())
            ->groupByRaw("DATE_FORMAT(p.created_at, '%Y-%m')")
            ->selectRaw("DATE_FORMAT(p.created_at, '%Y-%m') AS mes,
                COALESCE(SUM(p.amount),0) AS total,
                COALESCE(SUM(CASE WHEN DATEDIFF(DATE(p.created_at), d.date_facturation) > ? THEN p.amount ELSE 0 END),0) AS de_mora", [self::DIAS_PARA_MORA])
            ->get()
            ->keyBy('mes');

        // Los seis meses siempre, aunque alguno no tenga pagos: un hueco en el
        // gráfico también es información.
        $salida = [];

        for ($i = 5; $i >= 0; $i--) {
            $mes = now()->startOfMonth()->subMonths($i)->format('Y-m');
            $fila = $porMes[$mes] ?? null;
            $salida[] = [
                'mes'     => $mes,
                'total'   => round((float) ($fila->total ?? 0), 2),
                'de_mora' => round((float) ($fila->de_mora ?? 0), 2),
            ];
        }

        return $salida;
    }

    /**
     * Cuántos clientes pagaron en los días siguientes a cada tipo de aviso
     * (últimos 30 días) y cuánto se cobró de ellos.
     */
    private function efectoDeRecordatorios(int $empresa): array
    {
        $avisos = DB::table('wa_avisos_enviados')->where('company_id', $empresa)
            ->where('enviado_en', '>=', now()->subDays(30))
            ->get(['user_id', 'evento', 'enviado_en']);

        $salida = [];

        foreach ($avisos->groupBy('evento') as $evento => $delEvento) {
            $pagaron = 0;
            $cobrado = 0.0;

            foreach ($delEvento as $a) {
                $monto = (float) DB::table('payment_logs as p')
                    ->join('cab_facturations as c', 'c.id', '=', 'p.cab_id')
                    ->where('p.company_id', $empresa)
                    ->where('c.user_id', $a->user_id)
                    ->whereBetween('p.created_at', [$a->enviado_en, now()->parse($a->enviado_en)->addDays(self::DIAS_EFECTO_AVISO)])
                    ->sum('p.amount');

                if ($monto > 0) {
                    $pagaron++;
                    $cobrado += $monto;
                }
            }

            $salida[] = [
                'evento'   => $evento,
                'enviados' => $delEvento->count(),
                'pagaron'  => $pagaron,
                'cobrado'  => round($cobrado, 2),
                'dias'     => self::DIAS_EFECTO_AVISO,
            ];
        }

        return $salida;
    }
}
