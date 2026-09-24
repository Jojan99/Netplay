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
     * @param  string  $returnTo  'whatsapp' devuelve al chat al terminar de pagar;
     *                             'web', al portal.
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
        string $returnTo = 'web',
        ?array $paymentMethods = null,
        ?string $customerPhone = null,
        // OnePay puede mandarle el WhatsApp al cliente desde su propio canal
        // aprobado por Meta. La referencia, la transacción y la conciliación
        // son las mismas: lo único que cambia es quién entrega el link.
        bool $porWhatsapp = false,
        ?int $plantillaWhatsapp = null,
    ): array {
        $firstInvoice = $invoices->first();
        $orderedIds   = $invoices->pluck('id')->values()->all();

        // Referencia única: evita colisiones con múltiples intentos simultáneos.
        $tag       = count($orderedIds) > 1 ? 'MULTI' : $firstInvoice->number_facture;
        $reference = $company->slug . '-' . $tag . '-' . time() . '-' . $firstInvoice->id;

        // Un cobro vivo por estas mismas facturas se reusa en vez de crear
        // otro. Si no, un cliente que pide pagar dos veces —o al que le llega
        // el cobro por dos lados— termina con dos links por la misma deuda y
        // puede pagarla dos veces. Se devuelve el que ya tiene.
        if ($vivo = $this->cobroVivo($company, $orderedIds, $amount)) {
            Log::info('Pago online reusado', [
                'company_id' => $company->id,
                'origin'     => $origin,
                'reference'  => $vivo->reference,
            ]);

            return [
                'payment_url'  => (string) $vivo->payment_url,
                'reference'    => $vivo->reference,
                'amount'       => (float) $vivo->amount,
                'gateway'      => $company->pg_gateway,
                'sandbox'      => (bool) $vivo->sandbox,
                'breakdown'    => $this->computeBreakdown($invoices, $amount),
                'expires_on'   => $limitDate,
                'por_whatsapp' => false,
                'reusado'      => true,
            ];
        }

        $userData      = DB::table('user_data')->where('user_id', $clientUserId)->first(['names', 'lastname', 'email', 'phone']);
        $customerEmail = $userData->email ?? '';
        $customerName  = trim(($userData->names ?? '') . ' ' . ($userData->lastname ?? ''));

        $description = count($orderedIds) > 1
            ? 'Pago ' . count($orderedIds) . ' facturas'
            : 'Factura #' . $firstInvoice->number_facture;

        // Página propia de retorno. El origen se marca aquí, donde se conoce con
        // certeza: rastrearlo después por el link falla si el cliente lo abrió
        // más de una vez, porque el link solo recuerda su último intento.
        $redirectUrl = $redirectUrl
            ?: url('/api/pay/result/' . $reference) . '?via=' . ($returnTo === 'whatsapp' ? 'wa' : 'web');

        $redirectUrl .= (str_contains($redirectUrl, '?') ? '&' : '?') . 'tx=' . urlencode($reference);

        $gateway = PaymentGatewayFactory::make($company);
        $datosDelCobro = [
            'reference'      => $reference,
            'amount'         => $amount,
            'description'    => $description,
            'customer_email' => $customerEmail,
            'customer_name'  => $customerName,
            'redirect_url'   => $redirectUrl,
            'limit_date'     => $limitDate,
            // Cuando viene, el checkout muestra solo estos medios.
            'payment_methods'=> $paymentMethods,
            // Para Nequi: el cobro le llega como notificación a ese número.
            'customer_phone' => $customerPhone ?: ($userData->phone ?? null),
        ];

        $mandadoPorWhatsapp = false;

        if ($porWhatsapp && $gateway instanceof OnePayGateway) {
            $cobro = $gateway->cobrarPorWhatsapp($datosDelCobro, $plantillaWhatsapp);
            $link  = $cobro['payment_link'] ?? '';
            $mandadoPorWhatsapp = true;
        } else {
            $link = $gateway->generatePaymentLink($datosDelCobro);
        }

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
            'payment_url'            => $link,
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
            // Para que la pantalla sepa si el cliente ya recibió el mensaje o
            // si todavía hay que hacerle llegar el link por otro lado.
            'por_whatsapp' => $mandadoPorWhatsapp,
        ];
    }

    /**
     * Un cobro pendiente por exactamente estas facturas y este monto.
     *
     * Se mira sólo lo reciente: un link de hace una semana probablemente ya
     * venció del lado de la pasarela, y devolverlo sería peor que crear uno
     * nuevo. Tampoco se reusa si no quedó guardado el link, porque entonces no
     * hay nada que devolver.
     *
     * @param  list<int>  $invoiceIds
     */
    private function cobroVivo(Company $company, array $invoiceIds, float $amount): ?OnlinePaymentTransaction
    {
        sort($invoiceIds);

        return OnlinePaymentTransaction::where('company_id', $company->id)
            ->where('gateway', $company->pg_gateway)
            ->where('status', 'pending')
            ->where('sandbox', (bool) $company->pg_sandbox)
            ->whereNotNull('payment_url')
            ->where('created_at', '>=', now()->subHours(self::HORAS_QUE_VIVE_UN_COBRO))
            ->orderByDesc('id')
            ->get()
            ->first(function (OnlinePaymentTransaction $t) use ($invoiceIds, $amount) {
                $suyas = array_map('intval', (array) ($t->invoice_ids ?? []));
                sort($suyas);

                // El monto también: un abono parcial y el total de la misma
                // factura son dos cobros distintos y no se pueden confundir.
                return $suyas === $invoiceIds && abs((float) $t->amount - $amount) < 0.01;
            });
    }

    /** Cuánto vale reusar un cobro ya creado antes de hacer otro. */
    private const HORAS_QUE_VIVE_UN_COBRO = 24;

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
