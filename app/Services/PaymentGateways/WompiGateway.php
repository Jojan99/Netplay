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

    /**
     * Consulta el estado real de una transacción en Wompi.
     *
     * Hace falta porque el aviso al cliente depende del webhook, y si Wompi no
     * lo tiene configurado —o se demora— la transacción se queda en "pending"
     * para siempre: el cliente ve "estamos confirmando" y nunca le llega el
     * resultado. Con el id que Wompi devuelve en la URL de retorno se le
     * pregunta directamente y se resuelve sin depender del webhook.
     *
     * @return array{status:string, amount:float, reference:?string}|null
     */
    public function consultarTransaccion(string $transactionId): ?array
    {
        $base = $this->company->pg_sandbox
            ? 'https://sandbox.wompi.co/v1'
            : 'https://production.wompi.co/v1';

        try {
            $respuesta = \Illuminate\Support\Facades\Http::timeout(15)
                ->withToken((string) $this->company->pg_public_key)
                ->get("{$base}/transactions/{$transactionId}");

            if (!$respuesta->successful()) {
                \Illuminate\Support\Facades\Log::warning('[Wompi] No se pudo consultar la transacción', [
                    'id' => $transactionId, 'status' => $respuesta->status(),
                ]);
                return null;
            }

            $d = $respuesta->json('data');

            if (!is_array($d) || empty($d['status'])) {
                return null;
            }

            return [
                // Wompi usa APPROVED / DECLINED / VOIDED / ERROR / PENDING
                'status'    => match (strtoupper($d['status'])) {
                    'APPROVED' => 'approved',
                    'DECLINED' => 'declined',
                    'VOIDED'   => 'cancelled',
                    'ERROR'    => 'failed',
                    default    => 'pending',
                },
                'amount'    => isset($d['amount_in_cents']) ? ((int) $d['amount_in_cents']) / 100 : 0.0,
                'reference' => $d['reference'] ?? null,
            ];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[Wompi] Error consultando la transacción', [
                'id' => $transactionId, 'error' => $e->getMessage(),
            ]);
            return null;
        }
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
