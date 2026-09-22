<?php

namespace App\Services\PaymentGateways;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class WompiGateway implements PaymentGatewayInterface
{
    /** El id que devuelve Wompi cuando la transacción se crea directo. */
    private ?string $lastGatewayReference = null;

    private const CHECKOUT_URL = 'https://checkout.wompi.co/p/';

    public function __construct(private Company $company) {}

    public function generatePaymentLink(array $data): string
    {
        $amountCents = (int) round($data['amount'] * 100);
        $integrity   = $this->buildIntegrityHash($data['reference'], $amountCents);

        // Un solo medio: se crea la transacción directo y el cliente cae en el
        // banco, sin la pantalla de "elegí cómo pagar". Es lo que hace que un
        // botón de WhatsApp se sienta un botón y no un formulario.
        if (($data['payment_methods'] ?? []) === ['NEQUI'] && !empty($data['customer_phone'])) {
            $push = $this->cobroPorNequi($data, $amountCents, $integrity);

            if ($push) {
                return $push;
            }
        }

        if (($data['payment_methods'] ?? []) === ['BANCOLOMBIA_TRANSFER']) {
            $directo = $this->pagoDirectoBancolombia($data, $amountCents, $integrity);

            if ($directo) {
                return $directo;
            }

            // Si el banco no contestó, el checkout de siempre: mejor un paso de
            // más que quedarse sin poder pagar.
        }

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
     * Los medios que acepta la cuenta de la empresa.
     *
     * En pruebas Wompi los habilita todos, así que esto sólo dice la verdad en
     * producción: ahí cada medio hay que activarlo con el banco. Se consulta
     * para no ofrecerle al cliente un botón que después le da error.
     *
     * @return list<string>
     */
    public function metodosAceptados(): array
    {
        $clave = "wompi:metodos:{$this->company->id}:" . ($this->company->pg_sandbox ? 'pruebas' : 'produccion');

        return Cache::remember($clave, now()->addHour(), function () {
            $r = $this->pedir("{$this->base()}/merchants/{$this->company->pg_public_key}");

            return array_values(array_filter((array) ($r['data']['accepted_payment_methods'] ?? [])));
        });
    }

    /**
     * Crea la transacción de Bancolombia y devuelve la URL del banco.
     *
     * Wompi la entrega de forma asíncrona: la transacción nace en "PENDING" y
     * un segundo después aparece la dirección a la que hay que mandar al
     * cliente. Por eso se consulta unas cuantas veces antes de rendirse.
     */
    private function pagoDirectoBancolombia(array $data, int $amountCents, string $integrity): ?string
    {
        $base = $this->base();

        try {
            $comercio = $this->pedir("{$base}/merchants/{$this->company->pg_public_key}");
            $token = $comercio['data']['presigned_acceptance']['acceptance_token'] ?? null;
            $datos = $comercio['data']['presigned_personal_data_auth']['acceptance_token'] ?? null;

            if (!$token) {
                return null;
            }

            $r = $this->pedir("{$base}/transactions", [
                'acceptance_token'     => $token,
                'accept_personal_auth' => $datos,
                'amount_in_cents'      => $amountCents,
                'currency'             => 'COP',
                'customer_email'       => $data['customer_email'] ?: 'pagos@netvula.com',
                'reference'            => $data['reference'],
                'signature'            => $integrity,
                'redirect_url'         => $data['redirect_url'],
                'payment_method'       => [
                    'type'                => 'BANCOLOMBIA_TRANSFER',
                    'user_type'           => 'PERSON',
                    'payment_description' => mb_substr((string) ($data['description'] ?? 'Pago de tu servicio'), 0, 64),
                    'ecommerce_url'       => url('/'),
                ] + ($this->company->pg_sandbox ? ['sandbox_status' => 'APPROVED'] : []),
            ]);

            $id = $r['data']['id'] ?? null;

            if (!$id) {
                Log::warning('[Wompi] Bancolombia no creó la transacción', ['error' => $r['error'] ?? null]);

                return null;
            }

            $this->lastGatewayReference = $id;

            for ($intento = 0; $intento < 6; $intento++) {
                usleep(1_200_000);
                $t = $this->pedir("{$base}/transactions/{$id}");
                $url = $t['data']['payment_method']['extra']['async_payment_url'] ?? null;

                if ($url) {
                    return $url;
                }
            }

            Log::warning('[Wompi] Bancolombia no devolvió la dirección del banco a tiempo', ['transaccion' => $id]);
        } catch (\Throwable $e) {
            Log::warning('[Wompi] Falló el pago directo con Bancolombia', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Cobro por Nequi: le llega la notificación a su app y aprueba ahí.
     *
     * No hay página que abrir. Devuelve una dirección propia donde el cliente
     * ve cómo va el cobro, por si quiere mirar; lo que importa pasa en su
     * teléfono. Requiere que Nequi esté habilitado en la cuenta.
     */
    private function cobroPorNequi(array $data, int $amountCents, string $integrity): ?string
    {
        $telefono = preg_replace('/\D/', '', (string) ($data['customer_phone'] ?? ''));
        $telefono = substr($telefono, -10);

        if (strlen($telefono) !== 10) {
            return null;
        }

        try {
            $comercio = $this->pedir("{$this->base()}/merchants/{$this->company->pg_public_key}");
            $token = $comercio['data']['presigned_acceptance']['acceptance_token'] ?? null;
            $datos = $comercio['data']['presigned_personal_data_auth']['acceptance_token'] ?? null;

            if (!$token) {
                return null;
            }

            $r = $this->pedir("{$this->base()}/transactions", [
                'acceptance_token'     => $token,
                'accept_personal_auth' => $datos,
                'amount_in_cents'      => $amountCents,
                'currency'             => 'COP',
                'customer_email'       => $data['customer_email'] ?: 'pagos@netvula.com',
                'reference'            => $data['reference'],
                'signature'            => $integrity,
                'redirect_url'         => $data['redirect_url'],
                'payment_method'       => [
                    'type'         => 'NEQUI',
                    'phone_number' => $telefono,
                ] + ($this->company->pg_sandbox ? ['sandbox_status' => 'APPROVED'] : []),
            ]);

            $id = $r['data']['id'] ?? null;

            if (!$id) {
                Log::warning('[Wompi] Nequi no aceptó el cobro', ['error' => $r['error'] ?? null]);

                return null;
            }

            $this->lastGatewayReference = $id;

            // La página de retorno de la plataforma, que ya sabe consultar el
            // estado y devolver al chat.
            return $data['redirect_url'];
        } catch (\Throwable $e) {
            Log::warning('[Wompi] Falló el cobro por Nequi', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /** Una llamada a Wompi: GET si no hay cuerpo, POST si lo hay. */
    private function pedir(string $url, ?array $cuerpo = null): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $this->company->pg_public_key],
        ] + ($cuerpo ? [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($cuerpo)] : []));

        $respuesta = curl_exec($ch);
        curl_close($ch);

        return json_decode((string) $respuesta, true) ?: [];
    }

    /** La dirección de Wompi según el modo de la empresa. */
    private function base(): string
    {
        return $this->company->pg_sandbox ? 'https://sandbox.wompi.co/v1' : 'https://production.wompi.co/v1';
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

    /**
     * El checkout no entrega id; el pago directo con Bancolombia sí, y sirve
     * para consultar la transacción sin depender del webhook.
     */
    public function getLastGatewayReference(): ?string
    {
        return $this->lastGatewayReference;
    }

    private function buildIntegrityHash(string $reference, int $amountCents): string
    {
        $secret = $this->company->pg_integrity_secret;
        return hash('sha256', $reference . $amountCents . 'COP' . $secret);
    }
}
