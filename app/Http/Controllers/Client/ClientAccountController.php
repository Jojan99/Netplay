<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Extras del portal del cliente: estado del servicio, historial de pagos con
 * comprobante, contratos firmados y avisos. Todo acotado al cliente autenticado
 * y a su empresa.
 */
class ClientAccountController extends Controller
{
    private function client(): ?object
    {
        return JWTAuth::user();
    }

    /* ── GET client/status ─────────────────────────────────────────────
     * Estado del servicio + deuda, para el aviso del inicio del portal.
     */
    public function status(): JsonResponse
    {
        $user = $this->client();
        if (!$user) return response()->json(['status' => 1, 'message' => 'No autenticado.'], 401);

        $ud = DB::table('user_data as ud')
            ->leftJoin('internet_status as ist', 'ist.id', '=', 'ud.status_internet_id')
            ->leftJoin('internet_plans as ip', 'ip.id', '=', 'ud.internet_plans_id')
            ->where('ud.user_id', $user->id)
            ->first(['ud.active', 'ud.address', 'ist.name as internet_status', 'ip.plan_name', 'ip.download_speed', 'ip.upload_speed']);

        $deuda = DB::table('det_facturations as d')
            ->join('cab_facturations as cab', 'cab.id', '=', 'd.cab_id')
            ->where('cab.user_id', $user->id)
            ->where('cab.company_id', $user->company_id)
            ->where('d.paid', 0)
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(GREATEST(0, d.price_total - COALESCE(d.price_discount,0) - COALESCE(d.price_abone,0))),0) as total, MIN(d.date_facturation) as oldest')
            ->first();

        $estado = strtoupper((string) ($ud->internet_status ?? ''));
        $activo = str_starts_with($estado, 'ACT');
        $vencidas = DB::table('det_facturations as d')
            ->join('cab_facturations as cab', 'cab.id', '=', 'd.cab_id')
            ->where('cab.user_id', $user->id)->where('cab.company_id', $user->company_id)
            ->where('d.paid', 0)->whereDate('d.date_facturation', '<', now()->toDateString())
            ->count();

        $company = DB::table('companies')->where('id', $user->company_id)->first(['name', 'phone', 'email', 'invoice_payment_info']);

        return response()->json(['status' => 0, 'data' => [
            'service_active'   => $activo,
            'service_status'   => $ud->internet_status ?? 'Desconocido',
            'plan'             => $ud->plan_name ?? null,
            'download'         => $ud->download_speed ?? null,
            'upload'           => $ud->upload_speed ?? null,
            'address'          => $ud->address ?? null,
            'debt_total'       => (float) ($deuda->total ?? 0),
            'debt_count'       => (int) ($deuda->n ?? 0),
            'overdue_count'    => $vencidas,
            'oldest_due'       => $deuda->oldest ?? null,
            'company'          => ['name' => $company->name ?? '', 'phone' => $company->phone ?? '', 'email' => $company->email ?? '', 'payment_info' => $company->invoice_payment_info ?? null],
        ]]);
    }

    /* ── GET client/payments ───────────────────────────────────────────
     * Historial de pagos: en línea (pasarela) y facturas marcadas como pagadas.
     */
    public function payments(): JsonResponse
    {
        $user = $this->client();
        if (!$user) return response()->json(['status' => 1, 'message' => 'No autenticado.'], 401);

        $online = DB::table('online_payment_transactions')
            ->where('company_id', $user->company_id)
            ->whereIn('det_facturation_id', function ($q) use ($user) {
                $q->select('d.id')->from('det_facturations as d')
                  ->join('cab_facturations as cab', 'cab.id', '=', 'd.cab_id')
                  ->where('cab.user_id', $user->id);
            })
            ->orderByDesc('created_at')->limit(50)
            ->get(['id', 'reference', 'gateway', 'amount', 'status', 'paid_at', 'created_at', 'invoice_ids'])
            ->map(fn($t) => [
                'id'        => (int) $t->id,
                'kind'      => 'online',
                'reference' => $t->reference,
                'gateway'   => $t->gateway,
                'amount'    => (float) $t->amount,
                'status'    => $t->status,
                'date'      => $t->paid_at ?: $t->created_at,
                'invoice_ids' => json_decode((string) $t->invoice_ids, true) ?: [],
            ]);

        $facturas = DB::table('det_facturations as d')
            ->join('cab_facturations as cab', 'cab.id', '=', 'd.cab_id')
            ->where('cab.user_id', $user->id)->where('cab.company_id', $user->company_id)
            ->where('d.paid', 1)
            ->orderByDesc('d.paid_at')->limit(50)
            ->get(['d.id', 'd.number_facture', 'd.price_total', 'd.price_discount', 'd.paid_at', 'd.date_facturation'])
            ->map(fn($d) => [
                'id'        => (int) $d->id,
                'kind'      => 'invoice',
                'reference' => $d->number_facture,
                'gateway'   => null,
                'amount'    => round((float) $d->price_total - (float) ($d->price_discount ?? 0), 2),
                'status'    => 'approved',
                'date'      => $d->paid_at ?: $d->date_facturation,
                'invoice_ids' => [(int) $d->id],
            ]);

        // Los pagos en línea ya se reflejan como factura pagada: se prefiere la fila de pasarela
        $refInvoices = $online->pluck('invoice_ids')->flatten()->map(fn($i) => (int) $i)->all();
        $lista = $online->concat($facturas->reject(fn($f) => in_array($f['id'], $refInvoices, true)))
            ->sortByDesc('date')->values();

        return response()->json(['status' => 0, 'data' => [
            'payments' => $lista,
            'total_paid' => round((float) $lista->where('status', 'approved')->sum('amount'), 2),
        ]]);
    }

    /* ── GET client/payments/{invoiceId}/receipt ───────────────────────
     * Comprobante de pago de una factura (PDF), sólo si es del cliente.
     */
    public function receipt(int $invoiceId): JsonResponse
    {
        $user = $this->client();
        if (!$user) return response()->json(['status' => 1, 'message' => 'No autenticado.'], 401);

        $owns = DB::table('det_facturations as d')
            ->join('cab_facturations as cab', 'cab.id', '=', 'd.cab_id')
            ->where('d.id', $invoiceId)
            ->where('cab.user_id', $user->id)
            ->where('cab.company_id', $user->company_id)
            ->exists();

        if (!$owns) return response()->json(['status' => 1, 'message' => 'Comprobante no disponible.'], 404);

        return response()->json(['status' => 0, 'data' => [
            'url' => rtrim(config('app.url'), '/') . '/api/generatePdf/generatePaidPdfbyId/' . $invoiceId,
        ]]);
    }

    /* ── GET client/contracts ─────────────────────────────────────────── */
    public function contracts(): JsonResponse
    {
        $user = $this->client();
        if (!$user) return response()->json(['status' => 1, 'message' => 'No autenticado.'], 401);

        if (!DB::getSchemaBuilder()->hasTable('client_contracts')) {
            return response()->json(['status' => 0, 'data' => []]);
        }

        $rows = DB::table('client_contracts as cc')
            ->leftJoin('contracts as c', 'c.id', '=', 'cc.contract_id')
            ->where('cc.user_id', $user->id)
            ->orderByDesc('cc.id')
            ->get(['cc.id', 'cc.signed_at', 'cc.created_at', 'cc.status', 'c.title as contract_name'])
            ->map(fn($r) => [
                'id'        => (int) $r->id,
                'name'      => $r->contract_name ?: 'Contrato de servicio',
                'signed_at' => $r->signed_at,
                'created_at'=> $r->created_at,
                'status'    => $r->status,
                'pdf_url'   => rtrim(config('app.url'), '/') . '/api/contracts/pdf/' . $r->id,
            ]);

        return response()->json(['status' => 0, 'data' => $rows]);
    }

    /* ── GET client/activity ───────────────────────────────────────────
     * Últimos movimientos de la cuenta: facturas, pagos y reportes.
     */
    public function activity(): JsonResponse
    {
        $user = $this->client();
        if (!$user) return response()->json(['status' => 1, 'message' => 'No autenticado.'], 401);

        $items = collect();

        DB::table('det_facturations as d')
            ->join('cab_facturations as cab', 'cab.id', '=', 'd.cab_id')
            ->where('cab.user_id', $user->id)->where('cab.company_id', $user->company_id)
            ->orderByDesc('d.id')->limit(10)
            ->get(['d.id', 'd.number_facture', 'd.date_facturation', 'd.paid', 'd.paid_at', 'd.created_at'])
            ->each(function ($d) use ($items) {
                $items->push(['type' => $d->paid ? 'payment' : 'invoice',
                    'title' => $d->paid ? "Pago registrado · {$d->number_facture}" : "Factura {$d->number_facture}",
                    'date'  => $d->paid ? ($d->paid_at ?: $d->created_at) : $d->created_at,
                    'ref'   => (int) $d->id]);
            });

        DB::table('tickets')->where('user_id', $user->id)->where('company_id', $user->company_id)
            ->orderByDesc('id')->limit(10)
            ->get(['id', 'observation', 'created_at', 'finished_at'])
            ->each(function ($t) use ($items) {
                $items->push(['type' => 'ticket', 'title' => 'Reporte #' . $t->id, 'date' => $t->created_at, 'ref' => (int) $t->id]);
                if ($t->finished_at) $items->push(['type' => 'ticket_done', 'title' => 'Reporte #' . $t->id . ' resuelto', 'date' => $t->finished_at, 'ref' => (int) $t->id]);
            });

        return response()->json(['status' => 0, 'data' => $items->sortByDesc('date')->take(15)->values()]);
    }
}
