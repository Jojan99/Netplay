<?php

namespace App\Services;

use App\Models\Company;
use App\Models\UserData;

class WhatsAppService
{
    private bool   $enabled;
    private string $apiKey;
    private string $instanceId;
    private string $baseUrl;

    public function __construct(?int $companyId = null)
    {
        $id = $companyId ?? getSessionCompanyId();

        $company = $id ? Company::find($id) : null;

        if ($company && $company->wa_api_key && $company->wa_instance_id) {
            $this->enabled    = (bool) $company->whatsapp_enabled;
            $this->apiKey     = $company->wa_api_key;
            $this->instanceId = $company->wa_instance_id;
        } else {
            // Fallback a config global (.env) para compatibilidad
            $this->enabled    = (bool) config('services.netplay_whatsapp.enabled', false);
            $this->apiKey     = config('services.netplay_whatsapp.api_key', '');
            $this->instanceId = config('services.netplay_whatsapp.instance_id', '');
        }

        $this->baseUrl = rtrim(config('services.netplay_whatsapp.base_url', 'http://181.48.150.43:3001/crm'), '/');
    }

    // ── TEXTO ────────────────────────────────────────
    public function mensajeInformativo(string $to, string $body): bool
    {
        if (!$this->enabled) return false;
        $this->sendRequest('send', ['number' => $to, 'message' => $body]);
        return true;
    }

    // ── DOCUMENTO / PDF ──────────────────────────────
    public function sendDocument(string $to, string $documentUrl, string $filename, string $caption = ''): bool
    {
        if (!$this->enabled) return false;
        $this->sendRequest('send/document', ['number' => $to, 'url' => $documentUrl, 'filename' => $filename, 'caption' => $caption]);
        return true;
    }

    // ── DOCUMENTO / PDF (por contenido base64) ────────────────────────
    public function sendDocumentData(string $to, string $base64Content, string $filename, string $caption = '', string $mimetype = 'application/pdf'): bool
    {
        if (!$this->enabled) return false;
        $this->sendRequest('send/document-data', [
            'number'   => $to,
            'data'     => $base64Content,
            'filename' => $filename,
            'mimetype' => $mimetype,
            'caption'  => $caption,
        ]);
        return true;
    }

    // ── IMAGEN ───────────────────────────────────────
    public function sendImage(string $to, string $mediaUrl, string $caption = ''): bool
    {
        if (!$this->enabled) return false;
        $this->sendRequest('send/image', ['number' => $to, 'url' => $mediaUrl, 'caption' => $caption]);
        return true;
    }

    // ── VIDEO ────────────────────────────────────────
    public function sendVideo(string $to, string $mediaUrl, string $caption = ''): bool
    {
        if (!$this->enabled) return false;
        $this->sendRequest('send/video', ['number' => $to, 'url' => $mediaUrl, 'caption' => $caption]);
        return true;
    }

    // ── AUDIO ────────────────────────────────────────
    public function sendAudio(string $to, string $mediaUrl): bool
    {
        if (!$this->enabled) return false;
        $this->sendRequest('send/audio', ['number' => $to, 'url' => $mediaUrl, 'ptt' => false]);
        return true;
    }

    // ── NOTA DE VOZ ──────────────────────────────────
    public function sendVoice(string $to, string $mediaUrl): bool
    {
        if (!$this->enabled) return false;
        $this->sendRequest('send/audio', ['number' => $to, 'url' => $mediaUrl, 'ptt' => true]);
        return true;
    }

    // ── HELPER ESTÁTICO ──────────────────────────────
    /**
     * Verifica si un usuario tiene WhatsApp habilitado (campo user_data.whatsapp_enabled).
     */
    public static function isEnabledForUser(int $userId): bool
    {
        $ud = UserData::where('user_id', $userId)->first();
        return $ud ? (bool) $ud->whatsapp_enabled : true;
    }

    // ── CORE ─────────────────────────────────────────
    private function sendRequest(string $endpoint, array $params): void
    {
        $url  = "{$this->baseUrl}/instances/{$this->instanceId}/{$endpoint}";
        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($params),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                "x-api-key: {$this->apiKey}",
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($curl);
        $err      = curl_error($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($err) {
            throw new \RuntimeException("Error de conexión WA: {$err}");
        }

        $json = @json_decode($response, true);

        if ($httpCode < 200 || $httpCode >= 300) {
            $msg = $json['message'] ?? $response;
            throw new \RuntimeException("Error WA {$httpCode}: {$msg}");
        }
    }
}
