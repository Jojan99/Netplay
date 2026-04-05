<?php

namespace App\Services;

/**
 * Cliente HTTP para gestionar el whatsapp-service (Node.js).
 * Usa la master key para aprovisionamiento y la api_key de empresa para operaciones.
 */
class WhatsAppApiService
{
    private string $baseUrl;
    private string $masterKey;

    public function __construct()
    {
        $this->baseUrl   = rtrim(config('services.netplay_whatsapp.base_url', 'http://181.48.150.43:3001/crm'), '/');
        // Quitar el sufijo /crm para tener la raíz del servicio
        $this->baseUrl   = preg_replace('#/crm$#', '', $this->baseUrl);
        $this->masterKey = config('services.netplay_whatsapp.master_key', 'netplay_master_2026_xK9pLmQr');
    }

    // ────────────────────────────────────────────────
    // APROVISIONAMIENTO (master key)
    // ────────────────────────────────────────────────

    /**
     * Crea una empresa en el whatsapp-service y devuelve { id, api_key, ... }
     */
    public function provisionCompany(string $name, string $email, int $maxInstances = 1): array
    {
        return $this->request('POST', '/crm/companies', [
            'name'         => $name,
            'email'        => $email,
            'maxInstances' => $maxInstances,
        ], masterKey: true);
    }

    /**
     * Activa o renueva la suscripción de una empresa (usa master key).
     */
    public function subscribeCompany(string $companyId, string $planId = 'plan_pro', string $billingCycle = 'yearly'): array
    {
        return $this->request('POST', "/crm/companies/{$companyId}/subscribe", [
            'planId'       => $planId,
            'billingCycle' => $billingCycle,
            'pricePaid'    => 0,
            'notes'        => 'Auto-activado desde Netplay ISP',
        ], masterKey: true);
    }

    /**
     * Obtiene info de la empresa desde el WA service (incluye subscription_status).
     */
    public function getCompanyMe(string $apiKey): array
    {
        return $this->request('GET', '/crm/me', [], apiKey: $apiKey);
    }

    // ────────────────────────────────────────────────
    // INSTANCIAS (api_key de empresa)
    // ────────────────────────────────────────────────

    public function getInstances(string $apiKey): array
    {
        return $this->request('GET', '/crm/instances', [], apiKey: $apiKey);
    }

    public function getGroups(string $apiKey, string $instanceId): array
    {
        return $this->request('GET', "/crm/instances/{$instanceId}/groups", [], apiKey: $apiKey);
    }

    public function createInstance(string $apiKey, string $name): array
    {
        return $this->request('POST', '/crm/instances', ['name' => $name], apiKey: $apiKey);
    }

    public function deleteInstance(string $apiKey, string $instanceId): array
    {
        return $this->request('DELETE', "/crm/instances/{$instanceId}", [], apiKey: $apiKey);
    }

    public function getInstanceStatus(string $apiKey, string $instanceId): array
    {
        return $this->request('GET', "/crm/instances/{$instanceId}/status", [], apiKey: $apiKey);
    }

    /**
     * Devuelve la URL pública del QR (image/png).
     * El frontend puede usarla directamente en un <img>.
     */
    public function getInstanceQrUrl(string $instanceId): string
    {
        $root = rtrim($this->baseUrl, '/');
        return "{$root}/crm/instances/{$instanceId}/qr-image";
    }

    public function getInstanceQrImage(string $apiKey, string $instanceId): string
    {
        // Retorna el binario PNG para pasarlo al cliente
        return $this->rawRequest('GET', "/crm/instances/{$instanceId}/qr-image", [], apiKey: $apiKey);
    }

    // ────────────────────────────────────────────────
    // CORE
    // ────────────────────────────────────────────────

    private function request(
        string $method,
        string $path,
        array  $body = [],
        string $apiKey = '',
        bool   $masterKey = false,
    ): array {
        $url  = $this->baseUrl . $path;
        $curl = curl_init();

        $headers = ['Content-Type: application/json', 'Accept: application/json'];

        if ($masterKey) {
            $headers[] = 'x-master-key: ' . $this->masterKey;
        } elseif ($apiKey) {
            $headers[] = 'x-api-key: ' . $apiKey;
        }

        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
        ];

        if ($method === 'POST') {
            $opts[CURLOPT_POST]       = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        } elseif ($method === 'DELETE') {
            $opts[CURLOPT_CUSTOMREQUEST] = 'DELETE';
        } elseif ($method === 'PUT') {
            $opts[CURLOPT_CUSTOMREQUEST] = 'PUT';
            $opts[CURLOPT_POSTFIELDS]    = json_encode($body);
        }

        curl_setopt_array($curl, $opts);
        $response = curl_exec($curl);
        $err      = curl_error($curl);
        $code     = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($err) {
            throw new \RuntimeException("WA service connection error: {$err}");
        }

        $json = @json_decode($response, true) ?? [];

        if ($code < 200 || $code >= 300) {
            $msg = $json['message'] ?? $response;
            throw new \RuntimeException("WA service error {$code}: {$msg}");
        }

        return $json;
    }

    private function rawRequest(string $method, string $path, array $body = [], string $apiKey = ''): string
    {
        $url  = $this->baseUrl . $path;
        $curl = curl_init();

        $headers = ['x-api-key: ' . $apiKey];

        curl_setopt_array($curl, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($curl);
        curl_close($curl);

        return $response ?: '';
    }
}
