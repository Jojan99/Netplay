<?php

namespace App\Services\PaymentGateways;

use App\Models\CabFacturation;
use App\Models\DetFacturation;
use App\Models\OnlinePaymentTransaction;
use App\Models\PaymentLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Aplica un pago online aprobado sobre las facturas de la transacción,
 * en orden (más antigua primero) y completando cada una antes de seguir.
 *
 * Es idempotente: la bandera `allocation_done` impide que un webhook repetido
 * (o la conciliación por API) vuelva a abonar el mismo dinero.
 */
class PaymentAllocationService
{
    /** Diferencia máxima tolerada entre lo cobrado y lo registrado (redondeos). */
    private const AMOUNT_TOLERANCE = 1.0;

    public function allocate(int $companyId, string $reference, float $amountPaid, string $gateway): void
    {
        $tx = OnlinePaymentTransaction::where('reference', $reference)->first();
        if (!$tx) {
            Log::warning('Pago online: referencia sin transacción registrada', [
                'reference' => $reference, 'gateway' => $gateway,
            ]);
            return;
        }

        // Multi-empresa: la transacción debe pertenecer a la empresa que resolvió el webhook.
        if ((int) $tx->company_id !== $companyId) {
            Log::critical('Pago online: la transacción no pertenece a la empresa notificada', [
                'reference'      => $reference,
                'gateway'        => $gateway,
                'tx_company_id'  => $tx->company_id,
                'notified_company_id' => $companyId,
            ]);
            return;
        }

        // Idempotencia: si ya se distribuyó este pago, no volver a procesar
        if ($tx->allocation_done) return;

        // Un pago no puede abonar más de lo que se autorizó al iniciarlo.
        $expected = round((float) $tx->amount, 2);
        if ($amountPaid > $expected + self::AMOUNT_TOLERANCE) {
            Log::warning('Pago online: monto notificado mayor al autorizado, se aplica el autorizado', [
                'reference' => $reference,
                'gateway'   => $gateway,
                'notified'  => $amountPaid,
                'expected'  => $expected,
            ]);
            $amountPaid = $expected;
        }

        if ($amountPaid <= 0) return;

        // Determinar facturas a pagar (multi o single)
        $invoiceIds = !empty($tx->invoice_ids) ? $tx->invoice_ids : [$tx->det_facturation_id];
        $invoiceIds = array_values(array_filter($invoiceIds));

        if (empty($invoiceIds)) return;

        $invoices = DetFacturation::whereIn('id', $invoiceIds)->get()
            ->sortBy(fn($inv) => array_search($inv->id, $invoiceIds))
            ->values();

        $clientName  = 'Portal (online)';
        $cabResolved = false;

        DB::transaction(function () use (
            $invoices, $amountPaid, $tx, $gateway, $companyId, &$clientName, &$cabResolved
        ) {
            // Bloqueo pesimista: dos webhooks simultáneos no pueden abonar dos veces.
            $locked = OnlinePaymentTransaction::whereKey($tx->id)->lockForUpdate()->first();
            if (!$locked || $locked->allocation_done) return;

            $remaining = round($amountPaid, 2);

            foreach ($invoices as $invoice) {
                if ($remaining <= 0) break;

                // Resolver nombre de cliente (solo una vez)
                if (!$cabResolved) {
                    $cab = CabFacturation::find($invoice->cab_id);
                    if ($cab) {
                        $ud = DB::table('user_data')->where('user_id', $cab->user_id)->first(['names', 'lastname']);
                        if ($ud) {
                            $clientName = trim(($ud->names ?? '') . ' ' . ($ud->lastname ?? '')) ?: $clientName;
                        }
                    }
                    $cabResolved = true;
                }

                $alreadyPaid = round((float) ($invoice->abone ?? 0), 2);
                $stillOwed   = round($invoice->price_total - $alreadyPaid, 2);

                if ($stillOwed <= 0) continue;

                if ($remaining >= $stillOwed) {
                    // Pago completo de esta factura
                    $invoice->update([
                        'paid'    => 1,
                        'paid_at' => now(),
                        'abone'   => $invoice->price_total,
                    ]);
                    $applied   = $stillOwed;
                    $remaining = round($remaining - $stillOwed, 2);
                } else {
                    // Abono parcial
                    $invoice->update([
                        'abone' => $alreadyPaid + $remaining,
                    ]);
                    $applied   = $remaining;
                    $remaining = 0;
                }

                PaymentLog::create([
                    'company_id'          => $companyId,
                    'det_facturation_id'  => $invoice->id,
                    'cab_id'              => $invoice->cab_id,
                    'number_facture'      => $invoice->number_facture,
                    'client_name'         => $clientName,
                    'recorded_by_user_id' => null,
                    'amount'              => $applied,
                    'type'                => 'ingreso',
                    'notes'               => 'Pago online vía ' . strtoupper($gateway) . ". Ref: {$locked->reference}",
                    'payment_method_id'   => null,
                ]);
            }

            $locked->update(['allocation_done' => true]);
        });
    }
}
