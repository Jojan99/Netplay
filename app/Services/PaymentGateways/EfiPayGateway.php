<?php

namespace App\Services\PaymentGateways;

use App\Models\Company;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * EfiPay (Colombia) — https://efipay.co/docs/1.0/overview
 *
 * Flujo implementado: checkout tipo `redirect`.
 *   1. POST /api/v1/payment/generate-payment  → devuelve { saved, payment_id, url }
 *   2. Se redirige al cliente a esa `url` (checkout alojado por EfiPay: tarjeta, PSE, Bre-B, efectivo).
 *   3. EfiPay notifica el resultado al webhook con un header `Signature` (HMAC-SHA256 del body crudo).
 *   4. Reconciliación opcional: POST /api/v1/payment/transaction-status/{payment_id}
 *
 * El entorno (pruebas / producción) lo determina EfiPay a partir del token utilizado
 * (`api-access:test` o `api-access:production`), no se envía como parámetro.
 *
 * Credenciales en `companies`:
 *   pg_private_key   → token de acceso a la API (Bearer)
 *   pg_events_secret → token de webhook del comercio (llave HMAC de la firma)
 *   pg_office_id     → id de la sucursal (`office`)
 */
class EfiPayGateway implements PaymentGatewayInterface
{
    /** Host único de EfiPay: el entorno lo define el token. */
    private const API_BASE = 'https://sag.efipay.co';

    private const GENERATE_PATH = '/api/v1/payment/generate-payment';
    private const STATUS_PATH   = '/api/v1/payment/transaction-status/';
    private const OFFICES_PATH  = '/api/v1/offices/get';

    /** Límites documentados por EfiPay. */
    private const MAX_REFERENCE_LEN   = 50;
    private const MAX_DESCRIPTION_LEN = 191;
    private const MIN_DESCRIPTION_LEN = 4;

    private const HTTP_TIMEOUT         = 25;
    private const HTTP_CONNECT_TIMEOUT = 10;

    /** payment_id devuelto por la última generación (se persiste para reconciliar). */
    private ?string $lastPaymentId = null;

    public function __construct(private Company $company) {}

    // ─── Generación del link de pago ─────────────────────────────────────────

    public function generatePaymentLink(array $data): string
    {
        $token  = trim((string) $this->company->pg_private_key);
        $office = trim((string) $this->company->pg_office_id);

        if ($token === '') {
            throw new \RuntimeException('EfiPay: falta el token de acceso a la API. Configúralo en Pasarela de pago.');
        }
        if ($office === '' || !ctype_digit($office)) {
            throw new \RuntimeException('EfiPay: falta el ID de sucursal (office) o no es numérico. Configúralo en Pasarela de pago.');
        }

        $reference = (string) $data['reference'];
        if (strlen($reference) > self::MAX_REFERENCE_LEN) {
            // Nunca truncamos en silencio: la referencia es la llave con la que se
            // concilia el pago y una versión recortada rompería el webhook.
            throw new \RuntimeException(
                'EfiPay: la referencia supera los ' . self::MAX_REFERENCE_LEN . ' caracteres permitidos.'
            );
        }

        $amount = round((float) $data['amount'], 2);
        if ($amount < 1) {
            throw new \RuntimeException('EfiPay: el monto mínimo permitido es $1.');
        }

        $redirectUrl = $this->requireHttpsUrl($data['redirect_url'] ?? '', 'redirect_url');
        $webhookUrl  = $this->requireHttpsUrl($this->webhookUrl(), 'webhook');

        $payload = [
            'payment' => [
                'description'   => $this->normalizeDescription($data['description'] ?? ''),
                'amount'        => $amount,
                'currency_type' => 'COP',
                'checkout_type' => 'redirect',
            ],
            'advanced_options' => [
                'references'   => [$reference],
                'result_urls'  => [
                    'approved' => $redirectUrl,
                    'rejected' => $redirectUrl,
                    'pending'  => $redirectUrl,
                    'webhook'  => $webhookUrl,
                ],
                'has_comments' => false,
            ],
            'office' => (int) $office,
        ];

        $customer = $this->buildCustomerInformation($data);
        if ($customer !== []) {
            $payload['customer_information'] = $customer;
        }

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->asJson()
                ->connectTimeout(self::HTTP_CONNECT_TIMEOUT)
                ->timeout(self::HTTP_TIMEOUT)
                ->post(self::API_BASE . self::GENERATE_PATH, $payload);
        } catch (ConnectionException $e) {
            Log::error('EfiPay: no se pudo conectar con la pasarela', [
                'company_id' => $this->company->id,
                'reference'  => $reference,
                'error'      => $e->getMessage(),
            ]);
            throw new \RuntimeException('No se pudo conectar con EfiPay. Intenta de nuevo en unos minutos.');
        }

        if (!$response->successful()) {
            // El body puede traer errores de validación; nunca se registra el token.
            Log::error('EfiPay: generate-payment rechazado', [
                'company_id' => $this->company->id,
                'reference'  => $reference,
                'http_code'  => $response->status(),
                'body'       => mb_substr($response->body(), 0, 1000),
            ]);
            throw new \RuntimeException('EfiPay rechazó la solicitud de pago: ' . $this->extractApiError($response->json()));
        }

        $body = $response->json();

        if (!is_array($body) || ($body['saved'] ?? false) !== true || empty($body['url'])) {
            Log::error('EfiPay: respuesta inesperada de generate-payment', [
                'company_id' => $this->company->id,
                'reference'  => $reference,
                'body'       => mb_substr($response->body(), 0, 1000),
            ]);
            throw new \RuntimeException('EfiPay no devolvió un link de pago válido.');
        }

        $url = (string) $body['url'];

        // Defensa contra redirección abierta: solo aceptamos URLs del propio host de EfiPay.
        if (!$this->isEfipayUrl($url)) {
            Log::critical('EfiPay: URL de checkout fuera del dominio esperado', [
                'company_id' => $this->company->id,
                'reference'  => $reference,
                'host'       => parse_url($url, PHP_URL_HOST),
            ]);
            throw new \RuntimeException('EfiPay devolvió una URL de checkout no confiable.');
        }

        $this->lastPaymentId = isset($body['payment_id']) ? (string) $body['payment_id'] : null;

        return $url;
    }

    /** payment_id de EfiPay generado en la última llamada (para conciliar por API). */
    public function getLastGatewayReference(): ?string
    {
        return $this->lastPaymentId;
    }

    // ─── Webhook ─────────────────────────────────────────────────────────────

    /**
     * Verifica la firma HMAC-SHA256 que EfiPay envía en el header `Signature`,
     * calculada sobre el cuerpo crudo de la petición con el token de webhook del comercio.
     */
    public function verifyWebhook(Request $request): bool
    {
        $secret = trim((string) $this->company->pg_events_secret);

        if ($secret === '') {
            Log::warning('EfiPay webhook: comercio sin token de webhook configurado', [
                'company_id' => $this->company->id,
            ]);
            return false;
        }

        $received = trim((string) (
            $request->header('Signature')
            ?? $request->header('X-Signature')
            ?? $request->header('Efipay-Signature')
            ?? ''
        ));

        if ($received === '') {
            Log::warning('EfiPay webhook: petición sin header Signature', ['ip' => $request->ip()]);
            return false;
        }

        // Algunos emisores prefijan el algoritmo (`sha256=...`).
        if (str_contains($received, '=') && str_starts_with(strtolower($received), 'sha256=')) {
            $received = substr($received, 7);
        }

        $raw = $request->getContent();

        // La documentación no fija la codificación de la firma: aceptamos hex y base64,
        // siempre con comparación en tiempo constante.
        $candidates = [
            hash_hmac('sha256', $raw, $secret),                 // hex
            base64_encode(hash_hmac('sha256', $raw, $secret, true)), // base64
        ];

        $valid = false;
        foreach ($candidates as $candidate) {
            if (hash_equals($candidate, $received)) {
                $valid = true;
                break;
            }
        }

        if (!$valid) {
            Log::warning('EfiPay webhook: firma inválida', [
                'company_id' => $this->company->id,
                'ip'         => $request->ip(),
            ]);
            return false;
        }

        // Coherencia de entorno: un webhook de pruebas nunca puede cerrar una
        // factura de un comercio en producción (ni al revés).
        $production = $request->input('transaction.production');
        if ($production !== null) {
            $expectedProduction = !$this->company->pg_sandbox;
            if ((bool) $production !== $expectedProduction) {
                Log::warning('EfiPay webhook: entorno del pago no coincide con el del comercio', [
                    'company_id'          => $this->company->id,
                    'payload_production'  => (bool) $production,
                    'company_production'  => $expectedProduction,
                ]);
                return false;
            }
        }

        return true;
    }

    public function getInvoiceReference(Request $request): string
    {
        $references = $request->input('checkout.payment_gateway.advanced_option.references', []);

        if (is_array($references) && isset($references[0])) {
            return (string) $references[0];
        }

        // Rutas alternativas por si EfiPay cambia el anidamiento del payload.
        $fallback = $request->input('advanced_option.references.0')
                 ?? $request->input('checkout.advanced_option.references.0');

        return $fallback !== null ? (string) $fallback : '';
    }

    public function isApproved(Request $request): bool
    {
        return $this->getTransactionStatus($request) === 'approved';
    }

    public function getAmountPaid(Request $request): float
    {
        // `value_cop` viene ya convertido a pesos cuando la transacción es en otra moneda.
        $amount = $request->input('transaction.value_cop')
               ?? $request->input('transaction.amount')
               ?? 0;

        return round((float) $amount, 2);
    }

    public function getTransactionStatus(Request $request): string
    {
        return self::normalizeStatus((string) $request->input('transaction.status', ''));
    }

    /** ID de transacción de EfiPay presente en el webhook. */
    public function getGatewayTransactionId(Request $request): ?string
    {
        $id = $request->input('checkout.pivot.transaction_id')
           ?? $request->input('transaction.transaction_id');

        return $id !== null ? (string) $id : null;
    }

    // ─── Reconciliación por API ──────────────────────────────────────────────

    /**
     * Consulta el estado real del pago en EfiPay (fuente de verdad).
     * Devuelve null si no se pudo consultar.
     *
     * @return array{status: string, amount: float, transaction_id: ?string}|null
     */
    public function fetchStatus(string $paymentId): ?array
    {
        $token = trim((string) $this->company->pg_private_key);

        if ($token === '' || $paymentId === '') {
            return null;
        }

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->asJson()
                ->connectTimeout(self::HTTP_CONNECT_TIMEOUT)
                ->timeout(self::HTTP_TIMEOUT)
                ->post(self::API_BASE . self::STATUS_PATH . rawurlencode($paymentId));
        } catch (ConnectionException $e) {
            Log::warning('EfiPay: fallo al consultar el estado del pago', [
                'company_id' => $this->company->id,
                'error'      => $e->getMessage(),
            ]);
            return null;
        }

        if (!$response->successful()) {
            return null;
        }

        $body = $response->json();
        if (!is_array($body)) {
            return null;
        }

        $tx = $body['transaction'] ?? $body;

        $rawStatus = (string) ($tx['status'] ?? $body['status'] ?? '');
        if ($rawStatus === '') {
            return null;
        }

        return [
            'status'         => self::normalizeStatus($rawStatus),
            'amount'         => round((float) ($tx['value_cop'] ?? $tx['amount'] ?? 0), 2),
            'transaction_id' => isset($tx['transaction_id']) ? (string) $tx['transaction_id'] : null,
        ];
    }

    /**
     * Sucursales del comercio (GET /api/v1/offices/get). Sirve para que el
     * administrador elija un `office` válido en vez de adivinarlo.
     *
     * @return array<int, array{id: int|string, name: string}>
     * @throws \RuntimeException si el token no es válido o EfiPay no responde
     */
    public function fetchOffices(): array
    {
        $token = trim((string) $this->company->pg_private_key);

        if ($token === '') {
            throw new \RuntimeException('Guarda primero el token de acceso API de EfiPay.');
        }

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->connectTimeout(self::HTTP_CONNECT_TIMEOUT)
                ->timeout(self::HTTP_TIMEOUT)
                ->get(self::API_BASE . self::OFFICES_PATH);
        } catch (ConnectionException $e) {
            throw new \RuntimeException('No se pudo conectar con EfiPay. Intenta de nuevo en unos minutos.');
        }

        if ($response->status() === 401) {
            throw new \RuntimeException('EfiPay rechazó el token de acceso API. Verifica que corresponda al modo seleccionado.');
        }

        if (!$response->successful()) {
            throw new \RuntimeException('EfiPay no devolvió las sucursales: ' . $this->extractApiError($response->json()));
        }

        $body = $response->json();
        $rows = $body['data'] ?? $body['offices'] ?? $body;

        if (!is_array($rows)) {
            return [];
        }

        $offices = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['id'])) continue;

            $offices[] = [
                'id'   => $row['id'],
                'name' => (string) ($row['name'] ?? $row['description'] ?? ('Sucursal ' . $row['id'])),
            ];
        }

        return $offices;
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /** URL pública del webhook de esta empresa. */
    public function webhookUrl(): string
    {
        return url('/api/webhooks/efipay/' . $this->company->slug);
    }

    /** Traduce los estados de EfiPay (en español) al vocabulario interno. */
    public static function normalizeStatus(string $status): string
    {
        $normalized = mb_strtolower(trim($status));

        return match ($normalized) {
            'aprobada', 'aprobado', 'approved'          => 'approved',
            'rechazada', 'rechazado', 'declined'        => 'declined',
            'anulada', 'anulado', 'cancelada',
            'reversada', 'reversado', 'reembolsada'     => 'cancelled',
            'fallida', 'fallido', 'error'               => 'failed',
            default                                     => 'pending', // Pendiente, En proceso, …
        };
    }

    private function normalizeDescription(string $description): string
    {
        $clean = trim(preg_replace('/\s+/u', ' ', $description) ?? '');

        if (mb_strlen($clean) < self::MIN_DESCRIPTION_LEN) {
            $clean = 'Pago de factura';
        }

        return mb_substr($clean, 0, self::MAX_DESCRIPTION_LEN);
    }

    /**
     * EfiPay solo acepta `customer_information` completo y válido; enviar campos
     * a medias hace fallar la validación, así que se omite el bloque si no cumple.
     *
     * @return array<string, string>
     */
    private function buildCustomerInformation(array $data): array
    {
        $name  = trim((string) ($data['customer_name'] ?? ''));
        $email = trim((string) ($data['customer_email'] ?? ''));

        $customer = [];

        if (mb_strlen($name) >= 5 && mb_strlen($name) <= 255) {
            $customer['name'] = $name;
        }

        if ($email !== '' && mb_strlen($email) <= 255 && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $customer['email'] = $email;
        }

        return $customer;
    }

    private function requireHttpsUrl(string $url, string $field): string
    {
        $url = trim($url);

        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \RuntimeException("EfiPay: la URL de {$field} no es válida.");
        }

        if (!str_starts_with(strtolower($url), 'https://')) {
            throw new \RuntimeException("EfiPay: la URL de {$field} debe usar HTTPS.");
        }

        return $url;
    }

    private function isEfipayUrl(string $url): bool
    {
        $parts = parse_url($url);

        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https') {
            return false;
        }

        $expectedHost = parse_url(self::API_BASE, PHP_URL_HOST);

        return strcasecmp($parts['host'] ?? '', (string) $expectedHost) === 0;
    }

    private function extractApiError(mixed $body): string
    {
        if (!is_array($body)) {
            return 'error desconocido.';
        }

        // Bolsa de validación anidada: {"errors": {"campo": ["mensaje"]}}
        if (!empty($body['errors']) && is_array($body['errors'])) {
            $msg = $this->firstValidationMessage($body['errors']);
            if ($msg !== null) return $msg;
        }

        if (!empty($body['message'])) {
            return (string) $body['message'];
        }

        // Bolsa de validación plana, que es lo que devuelve EfiPay en los 422:
        // {"office": ["El office seleccionado no es válido."]}
        $msg = $this->firstValidationMessage($body);
        if ($msg !== null) return $msg;

        return 'error desconocido.';
    }

    /** Primer mensaje legible de un mapa campo => [mensajes]. */
    private function firstValidationMessage(array $bag): ?string
    {
        foreach ($bag as $field => $messages) {
            $text = is_array($messages) ? (string) reset($messages) : (string) $messages;
            if (trim($text) === '') continue;

            return is_string($field) && !is_numeric($field)
                ? "{$field}: {$text}"
                : $text;
        }

        return null;
    }
}
