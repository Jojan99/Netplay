<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Estado de cuenta de un cliente: sus facturas y, por cada una, cómo se pagó.
 *
 * El dato del pago está repartido en tres lugares y por eso antes no se veía junto:
 *  - payment_logs                → pagos y abonos registrados a mano (Nequi, Bancolombia, efectivo…)
 *  - online_payment_transactions → pagos por pasarela (Wompi, ePayco, EfiPay)
 *  - payment_proofs              → comprobantes que el cliente envió por WhatsApp
 */
class ClientStatementService
{
    /** Firma del enlace público del estado de cuenta. */
    private const FIRMA_LEN = 24;

    public static function tokenFor(int $userId): string
    {
        return $userId . '-' . substr(hash_hmac('sha256', 'estado-cuenta:' . $userId, (string) config('app.key')), 0, self::FIRMA_LEN);
    }

    public static function urlFor(int $userId): string
    {
        return url('/api/estado-cuenta/' . self::tokenFor($userId));
    }

    /** Devuelve el id del cliente si el token es válido. */
    public static function userFromToken(string $token): ?int
    {
        if (!str_contains($token, '-')) return null;
        [$id, $firma] = explode('-', $token, 2);
        if (!ctype_digit($id)) return null;
        $esperada = substr(hash_hmac('sha256', 'estado-cuenta:' . (int) $id, (string) config('app.key')), 0, self::FIRMA_LEN);
        return hash_equals($esperada, $firma) ? (int) $id : null;
    }

    /**
     * @return array{client:array, summary:array, invoices:array}|null
     */
    public function build(int $userId, ?int $companyId = null): ?array
    {
        $cliente = DB::table('user_data as ud')
            ->join('users as u', 'u.id', '=', 'ud.user_id')
            ->leftJoin('internet_plans as ip', 'ip.id', '=', 'ud.internet_plans_id')
            ->leftJoin('internet_status as ist', 'ist.id', '=', 'ud.status_internet_id')
            ->where('ud.user_id', $userId)
            ->when($companyId, fn($q) => $q->where('u.company_id', $companyId))
            ->first(['ud.user_id', 'ud.names', 'ud.lastname', 'ud.dni', 'ud.address', 'ud.phone', 'ud.email',
                     'u.company_id', 'ip.plan_name', 'ip.monthly_price', 'ist.name as service_status']);

        if (!$cliente) return null;
        $companyId = (int) $cliente->company_id;

        $facturas = DB::table('det_facturations as d')
            ->join('cab_facturations as cab', 'cab.id', '=', 'd.cab_id')
            ->where('cab.user_id', $userId)
            ->where('cab.company_id', $companyId)
            ->orderByDesc('d.date_facturation')->orderByDesc('d.id')
            ->get(['d.id', 'd.number_facture', 'd.date_facturation', 'd.price_total', 'd.price_discount',
                   'd.price_abone', 'd.paid', 'd.paid_at', 'd.created_at']);

        $ids = $facturas->pluck('id')->all();

        // Pagos manuales, agrupados por factura
        $manuales = [];
        if ($ids) {
            foreach (DB::table('payment_logs as pl')
                ->leftJoin('payment_methods as pm', 'pm.id', '=', 'pl.payment_method_id')
                ->leftJoin('user_data as ud', 'ud.user_id', '=', 'pl.recorded_by_user_id')
                ->whereIn('pl.det_facturation_id', $ids)
                ->where('pl.company_id', $companyId)
                ->orderBy('pl.created_at')
                ->get(['pl.det_facturation_id', 'pl.amount', 'pl.type', 'pl.notes', 'pl.created_at',
                       'pm.name as metodo', DB::raw("TRIM(CONCAT(COALESCE(ud.names,''),' ',COALESCE(ud.lastname,''))) as registrado_por")]) as $p) {
                $manuales[$p->det_facturation_id][] = [
                    'canal'    => 'manual',
                    'medio'    => $p->metodo ?: 'Pago registrado en caja',
                    'monto'    => (float) $p->amount,
                    'tipo'     => $p->type,
                    'fecha'    => $p->created_at,
                    'nota'     => $p->notes,
                    'operador' => trim((string) $p->registrado_por) ?: null,
                    'referencia' => null,
                ];
            }
        }

        // Pagos por pasarela: una transacción puede cubrir varias facturas
        $online = [];
        foreach (DB::table('online_payment_transactions')
            ->where('company_id', $companyId)
            ->where('status', 'approved')
            ->orderBy('created_at')
            ->get(['det_facturation_id', 'invoice_ids', 'gateway', 'amount', 'reference', 'paid_at', 'created_at', 'gateway_transaction_id']) as $tx) {
            $cubre = json_decode((string) $tx->invoice_ids, true) ?: [];
            if (!$cubre && $tx->det_facturation_id) $cubre = [(int) $tx->det_facturation_id];
            foreach ($cubre as $facturaId) {
                if (!in_array((int) $facturaId, $ids, true)) continue;
                $online[(int) $facturaId][] = [
                    'canal'      => 'pasarela',
                    'medio'      => self::etiquetaPasarela($tx->gateway),
                    'monto'      => (float) $tx->amount,
                    'tipo'       => 'pago en línea',
                    'fecha'      => $tx->paid_at ?: $tx->created_at,
                    'nota'       => null,
                    'operador'   => null,
                    'referencia' => $tx->reference ?: $tx->gateway_transaction_id,
                ];
            }
        }

        // Comprobantes enviados por el cliente (transferencias)
        $comprobantes = [];
        if ($ids && DB::getSchemaBuilder()->hasTable('payment_proofs')) {
            foreach (DB::table('payment_proofs')
                ->where('company_id', $companyId)
                ->whereIn('invoice_id', $ids)
                ->orderBy('created_at')
                ->get(['invoice_id', 'bank_name', 'reported_amount', 'detected_amount', 'reference_number', 'payment_date', 'status', 'created_at']) as $c) {
                $comprobantes[(int) $c->invoice_id][] = [
                    'canal'      => 'comprobante',
                    'medio'      => $c->bank_name ? 'Transferencia · ' . $c->bank_name : 'Transferencia',
                    'monto'      => (float) ($c->detected_amount ?: $c->reported_amount),
                    'tipo'       => 'comprobante ' . ($c->status ?: 'pendiente'),
                    'fecha'      => $c->payment_date ?: $c->created_at,
                    'nota'       => null,
                    'operador'   => null,
                    'referencia' => $c->reference_number,
                    'estado'     => $c->status,
                ];
            }
        }

        $lista = $facturas->map(function ($d) use ($manuales, $online, $comprobantes) {
            $pagos = array_merge($online[$d->id] ?? [], $manuales[$d->id] ?? [], $comprobantes[$d->id] ?? []);
            usort($pagos, fn($a, $b) => strcmp((string) $a['fecha'], (string) $b['fecha']));

            $total   = round((float) $d->price_total - (float) ($d->price_discount ?? 0), 2);
            $abonado = (float) ($d->price_abone ?? 0);
            $saldo   = $d->paid ? 0.0 : round(max(0, $total - $abonado), 2);

            // Cómo se pagó, en una frase: es lo que se ve en la lista
            $reales = array_values(array_filter($pagos, fn($p) => $p['canal'] !== 'comprobante' || ($p['estado'] ?? '') === 'approved'));
            $medios = array_values(array_unique(array_column($reales, 'medio')));

            return [
                'id'          => (int) $d->id,
                'number'      => $d->number_facture,
                'due_date'    => $d->date_facturation,
                'issued_at'   => $d->created_at,
                'total'       => $total,
                'paid_amount' => $abonado,
                'balance'     => $saldo,
                'paid'        => (bool) $d->paid,
                'paid_at'     => $d->paid_at,
                'status'      => $d->paid ? 'paid' : ($saldo < $total && $abonado > 0 ? 'partial'
                                 : (strtotime((string) $d->date_facturation) < strtotime(date('Y-m-d')) ? 'overdue' : 'pending')),
                'paid_with'   => $medios ? implode(' + ', $medios) : null,
                'payments'    => $pagos,
                'pdf_url'     => \App\Http\Controllers\InvoiceLinkController::urlFor((int) $d->id),
            ];
        })->values()->all();

        $pendientes = array_values(array_filter($lista, fn($f) => !$f['paid']));

        return [
            'client' => [
                'user_id' => (int) $cliente->user_id,
                'name'    => trim($cliente->names . ' ' . $cliente->lastname),
                'dni'     => $cliente->dni,
                'address' => $cliente->address,
                'phone'   => $cliente->phone,
                'email'   => $cliente->email,
                'plan'    => $cliente->plan_name,
                'service_status' => $cliente->service_status,
            ],
            'summary' => [
                'invoices'    => count($lista),
                'paid'        => count($lista) - count($pendientes),
                'pending'     => count($pendientes),
                'balance'     => round(array_sum(array_column($pendientes, 'balance')), 2),
                'total_paid'  => round(array_sum(array_map(fn($f) => $f['paid'] ? $f['total'] : $f['paid_amount'], $lista)), 2),
                'oldest_due'  => $pendientes ? min(array_column($pendientes, 'due_date')) : null,
            ],
            'invoices' => $lista,
        ];
    }

    public static function etiquetaPasarela(?string $g): string
    {
        return match ($g) {
            'wompi'    => 'Wompi',
            'epayco'   => 'ePayco',
            'efipay'   => 'EfiPay',
            'zonapago' => 'ZonaPago',
            default    => $g ? ucfirst($g) : 'Pago en línea',
        };
    }
}
