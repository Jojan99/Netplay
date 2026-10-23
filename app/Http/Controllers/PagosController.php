<?php

namespace App\Http\Controllers;

use App\Models\PaymentProof;
use App\Services\WhatsAppService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Finanzas › Pagos.
 *
 *  - Registrados: cada movimiento de payment_logs (pagos, abonos y reversos) con quién lo
 *    registró, por qué medio y su comprobante, y los totales del periodo por medio.
 *  - En línea: lo que pasó en las pasarelas, los pagos que el cliente empezó y no terminó
 *    (para escribirle) y los enlaces de pago.
 *  - Caja: lo registrado en un día por persona y por medio, y el cierre contra el banco o lo
 *    contado en efectivo.
 */
class PagosController extends Controller
{
    private const POR_PAGINA = 25;

    public function registrados(Request $request): JsonResponse
    {
        $empresa = (int) getSessionCompanyId();
        [$desde, $hasta] = $this->rango($request);

        $base = DB::table('payment_logs as pl')
            ->leftJoin('payment_methods as pm', 'pm.id', '=', 'pl.payment_method_id')
            ->leftJoin('cab_facturations as cb', 'cb.id', '=', 'pl.cab_id')
            ->leftJoin('det_facturations as dt', 'dt.id', '=', 'pl.det_facturation_id')
            ->leftJoin('user_data as quien', 'quien.user_id', '=', 'pl.recorded_by_user_id')
            ->where('pl.company_id', $empresa)
            ->whereBetween('pl.created_at', [$desde, $hasta]);

        if ($request->filled('medio')) {
            $request->input('medio') === 'sin'
                ? $base->whereNull('pl.payment_method_id')
                : $base->where('pl.payment_method_id', (int) $request->input('medio'));
        }
        if ($request->filled('quien')) {
            // «auto»: lo que no registró una persona (comprobantes aplicados solos y pagos en línea).
            $request->input('quien') === 'auto'
                ? $base->where(fn ($w) => $w->whereNull('pl.recorded_by_user_id')
                    ->orWhereNotExists(fn ($q) => $q->select(DB::raw(1))->from('user_data')->whereColumn('user_data.user_id', 'pl.recorded_by_user_id')))
                : $base->where('pl.recorded_by_user_id', (int) $request->input('quien'));
        }
        if (in_array($request->input('tipo'), ['pago_completo', 'abono', 'reverso'], true)) {
            $base->where('pl.type', $request->input('tipo'));
        }
        if ($request->filled('q')) {
            $q = '%' . trim((string) $request->input('q')) . '%';
            $base->where(fn ($w) => $w->where('pl.client_name', 'like', $q)->orWhere('pl.number_facture', 'like', $q)->orWhere('pl.notes', 'like', $q));
        }

        // Los totales salen del mismo filtro: lo que se ve arriba es lo que hay en la lista.
        $porMedio = (clone $base)->where('pl.type', '<>', 'reverso')
            ->groupBy('pl.payment_method_id', 'pm.name')
            ->orderByRaw('SUM(pl.amount) DESC')
            ->get([DB::raw('pl.payment_method_id as id'), DB::raw("COALESCE(pm.name, 'Sin medio registrado') as nombre"),
                DB::raw('COUNT(*) as pagos'), DB::raw('SUM(pl.amount) as total')]);

        $resumen = (clone $base)->selectRaw("
            SUM(pl.amount) as neto,
            SUM(pl.type <> 'reverso') as pagos,
            SUM(pl.type = 'abono') as abonos,
            SUM(pl.type = 'reverso') as reversos,
            SUM(CASE WHEN pl.type = 'reverso' THEN -pl.amount ELSE 0 END) as reversado")->first();

        $pagina = max(1, (int) $request->input('page', 1));
        $total = (clone $base)->count();
        $filas = (clone $base)->orderByDesc('pl.created_at')->orderByDesc('pl.id')
            ->offset(($pagina - 1) * self::POR_PAGINA)->limit(self::POR_PAGINA)
            ->get(['pl.id', 'pl.created_at', 'pl.amount', 'pl.type', 'pl.notes', 'pl.number_facture', 'pl.client_name',
                'pl.det_facturation_id', 'pl.payment_method_id', 'pm.name as medio', 'cb.user_id as cliente_id',
                'dt.date_facturation', 'dt.concepto', 'dt.paid',
                DB::raw("TRIM(CONCAT(COALESCE(quien.names,''),' ',COALESCE(quien.lastname,''))) as registro")]);

        // El comprobante aprobado de cada factura, para verlo junto al pago.
        $comprobantes = PaymentProof::where('company_id', $empresa)->where('status', 'approved')
            ->whereIn('invoice_id', $filas->pluck('det_facturation_id')->filter()->unique())
            ->get(['id', 'invoice_id', 'file_path', 'reference_number', 'bank_name'])
            ->keyBy('invoice_id');

        $filas->transform(function ($f) use ($comprobantes) {
            $c = $f->det_facturation_id ? $comprobantes->get($f->det_facturation_id) : null;
            $f->amount = (float) $f->amount;
            $f->registro = trim((string) $f->registro) ?: 'Automático';
            $f->comprobante = $c ? ['id' => $c->id, 'url' => $c->file_path, 'referencia' => $c->reference_number, 'banco' => $c->bank_name] : null;

            return $f;
        });

        return response()->json(['status' => 0, 'data' => [
            'desde' => $desde->toDateString(), 'hasta' => $hasta->toDateString(),
            'items' => $filas, 'total' => $total, 'pagina' => $pagina, 'por_pagina' => self::POR_PAGINA,
            'resumen' => [
                'neto' => (float) ($resumen->neto ?? 0), 'pagos' => (int) ($resumen->pagos ?? 0),
                'abonos' => (int) ($resumen->abonos ?? 0), 'reversos' => (int) ($resumen->reversos ?? 0),
                'reversado' => (float) ($resumen->reversado ?? 0),
            ],
            'por_medio' => $porMedio->map(fn ($m) => ['id' => $m->id, 'nombre' => $m->nombre, 'pagos' => (int) $m->pagos, 'total' => (float) $m->total]),
            'medios' => DB::table('payment_methods')->where('company_id', $empresa)->orderBy('name')->get(['id', 'name']),
            'quienes' => DB::table('payment_logs as pl')->join('user_data as u', 'u.user_id', '=', 'pl.recorded_by_user_id')
                ->where('pl.company_id', $empresa)->where('pl.created_at', '>=', now()->subMonths(3))
                ->distinct()->orderBy('nombre')->get(['pl.recorded_by_user_id as id', DB::raw("TRIM(CONCAT(COALESCE(u.names,''),' ',COALESCE(u.lastname,''))) as nombre")]),
        ]]);
    }

    public function enLinea(Request $request): JsonResponse
    {
        $empresa = (int) getSessionCompanyId();

        $pasarelas = DB::table('online_payment_transactions')->where('company_id', $empresa)->where('sandbox', 0)
            ->groupBy('gateway')
            ->get(['gateway', DB::raw("SUM(status = 'approved') as pagados"), DB::raw("SUM(status = 'pending') as pendientes"),
                DB::raw("SUM(status IN ('failed','declined','cancelled','error','expired')) as fallidos"),
                DB::raw("SUM(CASE WHEN status = 'approved' THEN amount ELSE 0 END) as recaudado")]);

        $sinTerminar = DB::table('online_payment_transactions as t')
            ->leftJoin('det_facturations as dt', 'dt.id', '=', 't.det_facturation_id')
            ->leftJoin('cab_facturations as cb', 'cb.id', '=', 'dt.cab_id')
            ->leftJoin('user_data as ud', 'ud.user_id', '=', 'cb.user_id')
            ->where('t.company_id', $empresa)->where('t.sandbox', 0)->where('t.status', 'pending')
            ->orderByDesc('t.created_at')->limit(100)
            ->get(['t.id', 't.reference', 't.gateway', 't.amount', 't.payment_url', 't.created_at', 't.initiated_at', 'cb.user_id as cliente_id',
                DB::raw("COALESCE(NULLIF(TRIM(CONCAT(COALESCE(ud.names,''),' ',COALESCE(ud.lastname,''))),''), t.customer_name) as cliente"), 'ud.phone']);

        $enlaces = DB::table('payment_links as pl')
            ->leftJoin('user_data as ud', 'ud.user_id', '=', 'pl.user_id')
            ->where('pl.company_id', $empresa)->orderByDesc('pl.created_at')->limit(30)
            ->get(['pl.id', 'pl.user_id', 'pl.invoice_ids', 'pl.created_via', 'pl.expires_at', 'pl.max_uses', 'pl.used_count', 'pl.last_used_at', 'pl.created_at',
                DB::raw("TRIM(CONCAT(COALESCE(ud.names,''),' ',COALESCE(ud.lastname,''))) as cliente")])
            ->map(function ($e) {
                $facturas = count((array) json_decode((string) $e->invoice_ids, true));
                $vencido = $e->expires_at && Carbon::parse($e->expires_at)->isPast();
                $e->facturas = $facturas;
                $e->estado = $e->used_count > 0 ? 'usado' : ($vencido ? 'vencido' : 'sin_usar');

                return $e;
            });

        $conteoEnlaces = DB::table('payment_links')->where('company_id', $empresa)
            ->selectRaw('COUNT(*) as creados, SUM(used_count > 0) as usados')->first();

        return response()->json(['status' => 0, 'data' => [
            'pasarelas' => $pasarelas->map(fn ($g) => [
                'nombre' => $this->nombrePasarela($g->gateway), 'clave' => $g->gateway,
                'pagados' => (int) $g->pagados, 'pendientes' => (int) $g->pendientes, 'fallidos' => (int) $g->fallidos, 'recaudado' => (float) $g->recaudado,
            ]),
            'sin_terminar' => $sinTerminar->map(fn ($t) => [
                'id' => $t->id, 'referencia' => $t->reference, 'pasarela' => $this->nombrePasarela($t->gateway), 'valor' => (float) $t->amount,
                'cliente' => $t->cliente ?: 'Sin nombre', 'cliente_id' => $t->cliente_id, 'desde' => $t->initiated_at ?: $t->created_at,
                'puede_escribirle' => (bool) ($t->payment_url && $t->phone),
            ]),
            'total_sin_terminar' => (float) $sinTerminar->sum('amount'),
            'enlaces' => $enlaces,
            'enlaces_creados' => (int) ($conteoEnlaces->creados ?? 0),
            'enlaces_usados' => (int) ($conteoEnlaces->usados ?? 0),
        ]]);
    }

    /** Recordarle por WhatsApp un pago que empezó y no terminó, con el mismo enlace para terminarlo. */
    public function escribirle(int $id): JsonResponse
    {
        $empresa = (int) getSessionCompanyId();
        $t = DB::table('online_payment_transactions as t')
            ->leftJoin('det_facturations as dt', 'dt.id', '=', 't.det_facturation_id')
            ->leftJoin('cab_facturations as cb', 'cb.id', '=', 'dt.cab_id')
            ->leftJoin('user_data as ud', 'ud.user_id', '=', 'cb.user_id')
            ->where('t.id', $id)->where('t.company_id', $empresa)
            ->first(['t.id', 't.status', 't.amount', 't.payment_url', 'cb.user_id', 'ud.names', 'ud.phone']);

        if (!$t) {
            return response()->json(['status' => 1, 'message' => 'Pago no encontrado.'], 404);
        }
        if ($t->status !== 'pending') {
            return response()->json(['status' => 1, 'message' => 'Ese pago ya no está pendiente.']);
        }
        $telefono = preg_replace('/\D/', '', (string) $t->phone);
        if (!$t->payment_url || strlen($telefono) < 10) {
            return response()->json(['status' => 1, 'message' => 'No hay enlace o teléfono para escribirle.']);
        }

        $nombre = trim(explode(' ', trim((string) $t->names))[0] ?? '') ?: 'cliente';
        $mensaje = "Hola {$nombre} 👋, vimos que empezó a pagar su factura de $ " . number_format((float) $t->amount, 0, ',', '.')
            . " y el pago no se completó. Puede terminarlo aquí:\n\n{$t->payment_url}\n\nSi ya pagó por otro medio, ignore este mensaje.";

        try {
            $r = (new WhatsAppService($empresa))->mensajeInformativo(strlen($telefono) === 10 ? '57' . $telefono : $telefono, $mensaje);
        } catch (\Throwable $e) {
            Log::warning('[Pagos] No se pudo escribir por el pago sin terminar', ['tx' => $id, 'error' => $e->getMessage()]);

            return response()->json(['status' => 1, 'message' => 'No se pudo enviar: ' . $e->getMessage()]);
        }
        if (isset($r['error']) || ($r['success'] ?? true) === false) {
            return response()->json(['status' => 1, 'message' => 'WhatsApp respondió con error: ' . ($r['message'] ?? $r['error'] ?? 'sin detalle')]);
        }

        if ($t->user_id) {
            DB::table('user_audit_logs')->insert([
                'user_id' => $t->user_id, 'changed_by' => (int) optional(auth()->user())->id, 'company_id' => $empresa,
                'field_changed' => 'datos_generales', 'old_value' => null, 'new_value' => null,
                'description' => 'Se le recordó por WhatsApp el pago en línea sin terminar de $ ' . number_format((float) $t->amount, 0, ',', '.'),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return response()->json(['status' => 0, 'message' => 'Mensaje enviado por WhatsApp.']);
    }

    public function caja(Request $request): JsonResponse
    {
        $empresa = (int) getSessionCompanyId();
        $fecha = $this->fecha($request);

        return response()->json(['status' => 0, 'data' => $this->datosDeCaja($empresa, $fecha) + [
            'cierres' => DB::table('caja_cierres as c')->leftJoin('user_data as u', 'u.user_id', '=', 'c.cerrado_por')
                ->where('c.company_id', $empresa)->orderByDesc('c.fecha')->limit(10)
                ->get(['c.fecha', 'c.total_sistema', 'c.total_contado', 'c.diferencia', 'c.nota', DB::raw("TRIM(CONCAT(COALESCE(u.names,''),' ',COALESCE(u.lastname,''))) as cerro")]),
        ]]);
    }

    public function cerrarCaja(Request $request): JsonResponse
    {
        $empresa = (int) getSessionCompanyId();
        $fecha = $this->fecha($request);
        $datos = $request->validate([
            'contado' => 'required|array',
            'contado.*.medio_id' => 'nullable',
            'contado.*.valor' => 'nullable|numeric|min:0',
            'nota' => 'nullable|string|max:500',
        ]);

        if ($fecha->isFuture()) {
            return response()->json(['status' => 1, 'message' => 'No se puede cerrar un día que no ha pasado.'], 422);
        }

        // Lo del sistema se vuelve a calcular aquí: lo que guarda el cierre no depende de la pantalla.
        $caja = $this->datosDeCaja($empresa, $fecha);
        $contado = collect($datos['contado'])->mapWithKeys(fn ($c) => [(string) ($c['medio_id'] ?? 'sin') => (float) ($c['valor'] ?? 0)]);

        $detalle = collect($caja['por_medio'])->map(function ($m) use ($contado) {
            $clave = (string) ($m['id'] ?? 'sin');
            $hay = $contado->has($clave) ? $contado[$clave] : null;

            return $m + ['contado' => $hay, 'diferencia' => $hay === null ? null : round($hay - $m['total'], 2)];
        })->values();

        if ($detalle->contains(fn ($m) => $m['contado'] === null)) {
            return response()->json(['status' => 1, 'message' => 'Falta escribir lo que hay en algún medio de pago.'], 422);
        }

        $totalContado = (float) $detalle->sum('contado');
        $diferencia = round($totalContado - $caja['total'], 2);
        $nota = trim((string) ($datos['nota'] ?? ''));

        if (abs($diferencia) >= 1 && $nota === '') {
            return response()->json(['status' => 1, 'message' => 'No cuadra: escriba en la nota por qué.'], 422);
        }

        DB::table('caja_cierres')->updateOrInsert(
            ['company_id' => $empresa, 'fecha' => $fecha->toDateString()],
            ['cerrado_por' => optional(auth()->user())->id, 'total_sistema' => $caja['total'], 'total_contado' => $totalContado,
                'diferencia' => $diferencia, 'detalle' => json_encode($detalle, JSON_UNESCAPED_UNICODE), 'nota' => $nota ?: null,
                'created_at' => now(), 'updated_at' => now()]
        );

        return response()->json(['status' => 0, 'message' => abs($diferencia) < 1 ? 'Caja cerrada: cuadra.' : 'Caja cerrada con diferencia; quedó la nota.']);
    }

    /** Lo registrado en un día, por persona y por medio, y el cierre si ya se hizo. */
    private function datosDeCaja(int $empresa, Carbon $fecha): array
    {
        $base = DB::table('payment_logs as pl')
            ->where('pl.company_id', $empresa)
            ->whereBetween('pl.created_at', [$fecha->copy()->startOfDay(), $fecha->copy()->endOfDay()]);

        $porPersona = (clone $base)->leftJoin('user_data as u', 'u.user_id', '=', 'pl.recorded_by_user_id')
            ->leftJoin('payment_methods as pm', 'pm.id', '=', 'pl.payment_method_id')
            ->groupBy('pl.recorded_by_user_id', 'u.names', 'u.lastname')
            ->orderByRaw('SUM(pl.amount) DESC')
            ->get([DB::raw('pl.recorded_by_user_id as id'), DB::raw("TRIM(CONCAT(COALESCE(u.names,''),' ',COALESCE(u.lastname,''))) as nombre"),
                DB::raw("SUM(pl.type <> 'reverso') as pagos"), DB::raw('SUM(pl.amount) as total'),
                DB::raw("GROUP_CONCAT(DISTINCT COALESCE(pm.name, 'sin medio') SEPARATOR ', ') as medios")]);

        $porMedio = (clone $base)->leftJoin('payment_methods as pm', 'pm.id', '=', 'pl.payment_method_id')
            ->groupBy('pl.payment_method_id', 'pm.name')
            ->orderByRaw('SUM(pl.amount) DESC')
            ->get([DB::raw('pl.payment_method_id as id'), DB::raw("COALESCE(pm.name, 'Sin medio registrado') as nombre"),
                DB::raw("SUM(pl.type <> 'reverso') as pagos"), DB::raw('SUM(pl.amount) as total')]);

        $cierre = DB::table('caja_cierres as c')->leftJoin('user_data as u', 'u.user_id', '=', 'c.cerrado_por')
            ->where('c.company_id', $empresa)->where('c.fecha', $fecha->toDateString())
            ->first(['c.*', DB::raw("TRIM(CONCAT(COALESCE(u.names,''),' ',COALESCE(u.lastname,''))) as cerro")]);

        if ($cierre) {
            $cierre->detalle = json_decode((string) $cierre->detalle, true);
        }

        return [
            'fecha' => $fecha->toDateString(),
            // Sin persona detrás: null son los pagos en línea; un id sin ficha es el usuario con que
            // el sistema aplica solo los comprobantes.
            'por_persona' => $porPersona->map(fn ($p) => ['id' => $p->id, 'nombre' => trim((string) $p->nombre) ?: ($p->id ? 'Automático (comprobantes)' : 'Pagos en línea'),
                'pagos' => (int) $p->pagos, 'total' => (float) $p->total, 'medios' => $p->medios]),
            'por_medio' => $porMedio->map(fn ($m) => ['id' => $m->id, 'nombre' => $m->nombre, 'pagos' => (int) $m->pagos, 'total' => (float) $m->total])->values()->all(),
            'total' => (float) $porMedio->sum('total'),
            'cierre' => $cierre,
        ];
    }

    /** Del 1.º del mes a hoy si no se pide otra cosa. */
    private function rango(Request $request): array
    {
        try {
            $desde = $request->filled('desde') ? Carbon::parse($request->input('desde'))->startOfDay() : now()->startOfMonth();
            $hasta = $request->filled('hasta') ? Carbon::parse($request->input('hasta'))->endOfDay() : now()->endOfDay();
        } catch (\Throwable) {
            [$desde, $hasta] = [now()->startOfMonth(), now()->endOfDay()];
        }

        return $desde->lte($hasta) ? [$desde, $hasta] : [$hasta->copy()->startOfDay(), $desde->copy()->endOfDay()];
    }

    private function fecha(Request $request): Carbon
    {
        try {
            return $request->filled('fecha') ? Carbon::parse($request->input('fecha'))->startOfDay() : now()->startOfDay();
        } catch (\Throwable) {
            return now()->startOfDay();
        }
    }

    private function nombrePasarela(?string $clave): string
    {
        return ['wompi' => 'Wompi', 'efipay' => 'Efipay', 'onepay' => 'OnePay', 'epayco' => 'ePayco'][strtolower((string) $clave)] ?? ucfirst((string) $clave);
    }
}
