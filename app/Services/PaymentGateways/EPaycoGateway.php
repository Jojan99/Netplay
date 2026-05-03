<?php

namespace App\Services\PaymentGateways;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class EPaycoGateway implements PaymentGatewayInterface
{
    public function __construct(private Company $company) {}

    /**
     * ePayco requiere un form POST, por lo que generamos una URL a nuestro
     * propio endpoint que renderiza y auto-envía el formulario.
     */
    public function generatePaymentLink(array $data): string
    {
        $payload = [
            'reference'    => $data['reference'],
            'amount'       => $data['amount'],
            'description'  => $data['description'],
            'email'        => $data['customer_email'] ?? '',
            'name'         => $data['customer_name']  ?? '',
            'redirect_url' => $data['redirect_url'],
            'company_id'   => $this->company->id,
        ];

        $token = urlencode(Crypt::encryptString(json_encode($payload)));

        return url("/api/payment-gateway/epayco/checkout/{$token}");
    }

    /**
     * Verifica la firma SHA256 que ePayco envía en el webhook de confirmación.
     */
    public function verifyWebhook(Request $request): bool
    {
        $clientId  = $this->company->pg_client_id;
        $secretKey = $this->company->pg_private_key;

        $refPayco       = $request->input('x_ref_payco', '');
        $transactionId  = $request->input('x_transaction_id', '');
        $amount         = $request->input('x_amount', '');
        $currency       = $request->input('x_currency_code', '');
        $receivedSign   = $request->input('x_signature', '');

        $computed = hash('sha256',
            $clientId . '^' . $secretKey . '^' .
            $refPayco . '^' . $transactionId . '^' .
            $amount . '^' . $currency
        );

        return hash_equals($computed, $receivedSign);
    }

    public function getInvoiceReference(Request $request): string
    {
        return $request->input('x_id_factura', '');
    }

    public function isApproved(Request $request): bool
    {
        // 1 = Aprobada, 2 = Rechazada, 3 = Pendiente, 4 = Fallida
        return (int) $request->input('x_cod_response', 0) === 1;
    }

    public function getAmountPaid(Request $request): float
    {
        return (float) $request->input('x_amount', 0);
    }

    public function getTransactionStatus(Request $request): string
    {
        // x_cod_response: 1=Aceptada, 2=Rechazada, 3=Pendiente, 4=Fallida
        return match ((int) $request->input('x_cod_response', 0)) {
            1 => 'approved',
            2 => 'declined',
            4 => 'failed',
            default => 'pending',
        };
    }

    /** Datos del formulario para renderizar el checkout. */
    public function buildFormData(array $payload): array
    {
        $clientId  = $this->company->pg_client_id;
        $secretKey = $this->company->pg_private_key;
        $testMode  = $this->company->pg_sandbox ? 'TRUE' : 'FALSE';

        $reference = $payload['reference'];
        $amount    = number_format((float) $payload['amount'], 2, '.', '');
        $currency  = 'COP';

        // Firma del formulario: md5(5 params) — p_base_tax NO va en la firma del form
        // SHA256 es solo para el webhook de confirmación (x_signature)
        $signature = md5($clientId . '^' . $secretKey . '^' . $reference . '^' . $amount . '^' . $currency);

        return [
            'checkout_url'       => 'https://secure.payco.co/checkout.php',
            'p_cust_id_cliente'  => $clientId,
            'p_key'              => $secretKey,
            'p_id_invoice'       => $reference,
            'p_description'      => $payload['description'] ?? 'Pago de factura',
            'p_amount'           => $amount,
            'p_currency_code'    => $currency,
            'p_tax'              => '0',
            'p_base_tax'         => '0',
            'p_email_billing'    => $payload['email'] ?? '',
            'p_billing_name'     => $payload['name']  ?? '',
            'p_url_response'     => $payload['redirect_url'],
            'p_url_confirmation' => url('/api/webhooks/epayco/' . $this->company->slug),
            'p_test_request'     => $testMode,
            'p_signature'        => $signature,
        ];
    }
}
