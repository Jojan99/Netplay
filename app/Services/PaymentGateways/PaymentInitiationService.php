<?php

namespace App\Services\PaymentGateways;

use App\Models\Company;
use App\Models\OnlinePaymentTransaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Punto único donde se pide un cobro a la pasarela y se registra la transacción.
 *
 * Lo usan tanto el portal del cliente como los links de pago compartibles, de
 * modo que la referencia, el registro y la conciliación posterior sean idénticos
 * sin importar por dónde entró el pago.
 */
class PaymentInitiationService
{
    /**
     * @param  Collection  $invoices  Facturas ya validadas como del cliente y sin pagar,
     *                                ordenadas de la más antigua a la más nueva.
     * @return array{payment_url: string, reference: string, amount: float, gateway: string,
     *               sandbox: bool, breakdown: array, expires_on: ?string}
     *
     * @throws \RuntimeException si la pasarela no entrega un link
     */
    public function initiate(
        Company $company,
        int $clientUserId,
        Collection $invoices,
        float $amount,
        ?string $redirectUrl = null,
        ?string $limitDate = null,
        string $origin = 'portal',
    ): array {
        $firstInvoice = $invoices->first();
        $orderedIds   = $invoices->pluck('id')->values()->all();

        // Referencia única: evita colisiones con múltiples intentos simultáneos.
        $tag       = count($orderedIds) > 1 ? 'MULTI' : $firstInvoice->number_facture;
        $reference = $company->slug . '-' . $tag . '-' . time() . '-' . $firstInvoice->id;

        $userData      = DB::table('user_data')->where('user_id', $clientUserId)->first(['names', 'lastname', 'email']);
        $customerEmail = $userData->email ?? '';
        $customerName  = trim(($userData->names ?? '') . ' ' . ($userData->lastname ?? ''));

        $description = count($orderedIds) > 1
            ? 'Pago ' . count($orderedIds) . ' facturas'
            : 'Factura #' . $firstInvoice->number_facture;

        $redirectUrl = $redirectUrl ?: url('/portal/facturas');
        $redirectUrl = rtrim($redirectUrl, '/') . '?tx=' . urlencode($reference);

        $gateway = PaymentGatewayFactory::make($company);
        $link    = $gateway->generatePaymentLink([
            'reference'      => $reference,
            'amount'         => $amount,
            'description'    => $description,
            'customer_email' => $customerEmail,
            'customer_name'  => $customerName,
            'redirect_url'   => $redirectUrl,
            'limit_date'     => $limitDate,
        ]);

        OnlinePaymentTransaction::create([
            'company_id'             => $company->id,
            'det_facturation_id'     => $firstInvoice->id,
            'invoice_ids'            => $orderedIds,
            'reference'              => $reference,
            'gateway'                => $company->pg_gateway,
            'sandbox'                => (bool) $company->pg_sandbox,
            'amount'                 => $amount,
            'status'                 => 'pending',
            'customer_name'          => $customerName,
            'customer_email'         => $customerEmail,
            'gateway_transaction_id' => $gateway->getLastGatewayReference(),
            'initiated_at'           => now(),
        ]);

        Log::info('Pago online iniciado', [
            'company_id' => $company->id,
            'origin'     => $origin,
            'reference'  => $reference,
            'invoices'   => count($orderedIds),
        ]);

        return [
            'payment_url' => $link,
            'reference'   => $reference,
            'amount'      => $amount,
            'gateway'     => $company->pg_gateway,
            'sandbox'     => (bool) $company->pg_sandbox,
            'breakdown'   => $this->computeBreakdown($invoices, $amount),
            'expires_on'  => $limitDate,
        ];
    }

    /** Cómo se repartirá el monto entre las facturas, de la más antigua a la más nueva. */
    public function computeBreakdown(Collection $invoices, float $amount): array
    {
        $remaining = $amount;
        $result    = [];

        foreach ($invoices as $invoice) {
            if ($remaining <= 0) break;

            $alreadyPaid = $invoice->amountPaid();
            $stillOwed   = $invoice->outstanding();
            $toPay       = min($remaining, $stillOwed);

            $result[] = [
                'invoice_id'     => $invoice->id,
                'number_facture' => $invoice->number_facture,
                'price_total'    => (float) $invoice->price_total,
                'already_paid'   => $alreadyPaid,
                'still_owed'     => $stillOwed,
                'amount_to_pay'  => round($toPay, 2),
                'full_coverage'  => $toPay >= $stillOwed - 0.01,
            ];

            $remaining = round($remaining - $toPay, 2);
        }

        return $result;
    }
}
