<?php

namespace App\Services\PaymentGateways;

use App\Models\Company;
use Illuminate\Http\Request;

class WompiGateway implements PaymentGatewayInterface
{
    private const CHECKOUT_URL = 'https://checkout.wompi.co/p/';

    public function __construct(private Company $company) {}

    public function generatePaymentLink(array $data): string
    {
        $amountCents = (int) round($data['amount'] * 100);
        $integrity   = $this->buildIntegrityHash($data['reference'], $amountCents);

        $params = [
            'public-key'              => $this->company->pg_public_key,
            'currency'                => 'COP',
            'amount-in-cents'         => $amountCents,
            'reference'               => $data['reference'],
            'signature:integrity'     => $integrity,
            'redirect-url'            => $data['redirect_url'],
            'customer-data:email'     => $data['customer_email'] ?? '',
            'customer-data:full-name' => $data['customer_name']  ?? '',
        ];

        return self::CHECKOUT_URL . '?' . http_build_query($params);
    }

    public function verifyWebhook(Request $request): bool
    {
        $eventsSecret = $this->company->pg_events_secret;
        $checksum     = $request->header('X-Event-Checksum');

        if (!$checksum || !$eventsSecret) return false;

        $signature = $request->input('signature', []);
        $properties = $signature['properties'] ?? [];
        $timestamp  = $request->input('timestamp', '');

        $data = $request->input('data', []);
        $transaction = $data['transaction'] ?? [];

        $values = [];
        foreach ($properties as $prop) {
            $keys = explode('.', $prop);
            $value = $transaction;
            foreach ($keys as $key) {
                $value = $value[$key] ?? '';
            }
            $values[] = $value;
        }

        $computed = hash('sha256', implode('', $values) . $timestamp . $eventsSecret);

        return hash_equals($computed, $checksum);
    }

    public function getInvoiceReference(Request $request): string
    {
        return $request->input('data.transaction.reference', '');
    }

    public function isApproved(Request $request): bool
    {
        return $request->input('data.transaction.status') === 'APPROVED';
    }

    public function getAmountPaid(Request $request): float
    {
        $cents = (int) $request->input('data.transaction.amount_in_cents', 0);
        return $cents / 100;
    }

    public function getTransactionStatus(Request $request): string
    {
        return match ($request->input('data.transaction.status')) {
            'APPROVED' => 'approved',
            'DECLINED' => 'declined',
            'VOIDED'   => 'cancelled',
            'ERROR'    => 'failed',
            default    => 'pending',
        };
    }

    /** Wompi no entrega un id de transacción al construir el link. */
    public function getLastGatewayReference(): ?string
    {
        return null;
    }

    private function buildIntegrityHash(string $reference, int $amountCents): string
    {
        $secret = $this->company->pg_integrity_secret;
        return hash('sha256', $reference . $amountCents . 'COP' . $secret);
    }
}
