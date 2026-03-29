<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Http\UploadedFile;

class WhatsAppService
{
    private bool $enabled;

    public function __construct()
    {
        $this->enabled = (bool) config('services.netplay_whatsapp.enabled', true);
    }

    /* =========================
       TEXTO
       ========================= */
    public function mensajeInformativo(string $to, string $body): bool
    {
        if (!$this->enabled) return false;

        $this->sendRequest('send', [
            'number'  => $to,
            'message' => $body,
        ]);

        return true;
    }

    /* =========================
       DOCUMENTO / PDF
       ========================= */
    public function sendDocument(
        string $to,
        string $documentUrl,
        string $filename,
        string $caption = ''
    ): bool {
        if (!$this->enabled) return false;

        $this->sendRequest('send/document', [
            'number'   => $to,
            'url'      => $documentUrl,
            'filename' => $filename,
            'caption'  => $caption,
        ]);

        return true;
    }

    /* =========================
       IMAGEN
       ========================= */
    public function sendImage(string $to, string $mediaUrl, string $caption = ''): bool
    {
        if (!$this->enabled) return false;

        $this->sendRequest('send/image', [
            'number'  => $to,
            'url'     => $mediaUrl,
            'caption' => $caption,
        ]);

        return true;
    }

    /* =========================
       VIDEO
       ========================= */
    public function sendVideo(string $to, string $mediaUrl, string $caption = ''): bool
    {
        if (!$this->enabled) return false;

        $this->sendRequest('send/video', [
            'number'  => $to,
            'url'     => $mediaUrl,
            'caption' => $caption,
        ]);

        return true;
    }

    /* =========================
       AUDIO NORMAL
       ========================= */
    public function sendAudio(string $to, string $mediaUrl): bool
    {
        if (!$this->enabled) return false;

        $this->sendRequest('send/audio', [
            'number' => $to,
            'url'    => $mediaUrl,
            'ptt'    => false,
        ]);

        return true;
    }

    /* =========================
       NOTA DE VOZ (PTT)
       ========================= */
    public function sendVoice(string $to, string $mediaUrl): bool
    {
        if (!$this->enabled) return false;

        $this->sendRequest('send/audio', [
            'number' => $to,
            'url'    => $mediaUrl,
            'ptt'    => true,
        ]);

        return true;
    }

    /* =========================
       CORE - ENVÍO HTTP
       ========================= */
    private function sendRequest(string $endpoint, array $params): void
    {
        $baseUrl    = config('services.netplay_whatsapp.base_url', 'http://181.48.150.43:3001/crm');
        $apiKey     = config('services.netplay_whatsapp.api_key');
        $instanceId = config('services.netplay_whatsapp.instance_id');

        $url = "{$baseUrl}/instances/{$instanceId}/{$endpoint}";

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($params),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                "x-api-key: {$apiKey}",
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($curl);
        $err      = curl_error($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($err) {
            throw new \Exception("Error de conexión: {$err}");
        }

        $json = @json_decode($response, true);

        if ($httpCode < 200 || $httpCode >= 300) {
            $msg = $json['message'] ?? $response;
            throw new \Exception("Error {$httpCode}: {$msg}");
        }
    }
}