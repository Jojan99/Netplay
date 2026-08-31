<?php

namespace App\Services;

use App\Models\Company;
use App\Models\UserData;

/**
 * Servicio de WhatsApp vía el servicio interno de Netplay (WhatsApp Web con QR).
 * URL base: http://181.48.150.43:3001/crm
 */
class NetplayWhatsAppService
{
    private bool   $enabled;
    private string $apiKey;
    private string $instanceId;
    private string $baseUrl;

    public function __construct(?int $companyId = null, bool $ignoreEnabledFlag = false)
    {
        $id = $companyId ?? getSessionCompanyId();

        $company = $id ? Company::find($id) : null;

        if ($company && $company->wa_api_key && $company->wa_instance_id) {
            // Si ignoreEnabledFlag es true, solo verificamos credenciales (útil para envío de facturas
            // cuando invoice_whatsapp_enabled está activo pero whatsapp_enabled global no lo está)
            $this->enabled    = $ignoreEnabledFlag ? true : (bool) $company->whatsapp_enabled;
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
    public function mensajeInformativo(string $to, string $body): array
    {
        if (!$this->enabled) return ['success' => false, 'error' => 'WhatsApp deshabilitado para esta empresa.'];
        return $this->sendRequest('send', ['number' => $to, 'message' => $body]);
    }

    // ── DOCUMENTO / PDF ──────────────────────────────
    public function sendDocument(string $to, string $documentUrl, string $filename, string $caption = ''): array
    {
        if (!$this->enabled) return ['success' => false, 'error' => 'WhatsApp deshabilitado para esta empresa.'];
        return $this->sendRequest('send/document', ['number' => $to, 'url' => $documentUrl, 'filename' => $filename, 'caption' => $caption]);
    }

    // ── DOCUMENTO / PDF (por contenido base64) ────────────────────────
    public function sendDocumentData(string $to, string $base64Content, string $filename, string $caption = '', string $mimetype = 'application/pdf'): array
    {
        if (!$this->enabled) return ['success' => false, 'error' => 'WhatsApp deshabilitado para esta empresa.'];
        return $this->sendRequest('send/document-data', [
            'number'   => $to,
            'data'     => $base64Content,
            'filename' => $filename,
            'mimetype' => $mimetype,
            'caption'  => $caption,
        ]);
    }

    // ── IMAGEN ───────────────────────────────────────
    public function sendImage(string $to, string $mediaUrl, string $caption = ''): array
    {
        if (!$this->enabled) return ['success' => false, 'error' => 'WhatsApp deshabilitado para esta empresa.'];
        return $this->sendRequest('send/image', ['number' => $to, 'url' => $mediaUrl, 'caption' => $caption]);
    }

    // ── VIDEO ────────────────────────────────────────
    public function sendVideo(string $to, string $mediaUrl, string $caption = ''): array
    {
        if (!$this->enabled) return ['success' => false, 'error' => 'WhatsApp deshabilitado para esta empresa.'];
        return $this->sendRequest('send/video', ['number' => $to, 'url' => $mediaUrl, 'caption' => $caption]);
    }

    // ── AUDIO ────────────────────────────────────────
    public function sendAudio(string $to, string $mediaUrl): array
    {
        if (!$this->enabled) return ['success' => false, 'error' => 'WhatsApp deshabilitado para esta empresa.'];
        return $this->sendRequest('send/audio', ['number' => $to, 'url' => $mediaUrl, 'ptt' => false]);
    }

    // ── NOTA DE VOZ ──────────────────────────────────
    public function sendVoice(string $to, string $mediaUrl): array
    {
        if (!$this->enabled) return ['success' => false, 'error' => 'WhatsApp deshabilitado para esta empresa.'];
        return $this->sendRequest('send/audio', ['number' => $to, 'url' => $mediaUrl, 'ptt' => true]);
    }

    // ── ENVÍO MASIVO / BATCH ─────────────────────────
    /**
     * Encola múltiples mensajes en el whatsapp-service para envío con rate limiting automático.
     * Divide en chunks para evitar error 413 Payload Too Large.
     *
     * @param array $messages Array de mensajes: [['number' => '...', 'message' => '...', 'type' => 'text'], ...]
     * @return array Respuesta acumulada: ['queued' => N, 'invalid' => N, 'chunks' => N]
     */
    public function sendBulk(array $messages): array
    {
        if (!$this->enabled) return ['queued' => 0, 'invalid' => count($messages), 'chunks' => 0];

        $chunkSize = 50; // Máximo mensajes por request para evitar 413
        $chunks = array_chunk($messages, $chunkSize);

        $totalQueued = 0;
        $totalInvalid = 0;
        $chunkCount = 0;

        // El whatsapp-service monta sus rutas en /instances/, no en /crm/instances/
        $baseUrl = preg_replace('#/crm$#', '', $this->baseUrl);
        $url = "{$baseUrl}/instances/{$this->instanceId}/send/batch";

        foreach ($chunks as $index => $chunk) {
            $chunkCount++;

            $curl = curl_init();
            curl_setopt_array($curl, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode(['messages' => $chunk]),
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    "x-api-key: {$this->apiKey}",
                ],
                CURLOPT_TIMEOUT        => 60,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);

            $response = curl_exec($curl);
            $err      = curl_error($curl);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);

            if ($err) {
                throw new \RuntimeException("Error de conexión WA Batch chunk {$chunkCount}: {$err}");
            }

            $json = @json_decode($response, true);

            if ($httpCode < 200 || $httpCode >= 300) {
                $msg = $json['message'] ?? $response;
                throw new \RuntimeException("Error WA Batch chunk {$chunkCount} HTTP {$httpCode}: {$msg}");
            }

            $totalQueued  += $json['queued'] ?? 0;
            $totalInvalid += $json['invalid'] ?? 0;

            // Pequeña pausa entre chunks para no saturar
            if ($index < count($chunks) - 1) {
                sleep(2);
            }
        }

        return [
            'queued'  => $totalQueued,
            'invalid' => $totalInvalid,
            'chunks'  => $chunkCount,
        ];
    }

    // ── CORE ─────────────────────────────────────────
    private function sendRequest(string $endpoint, array $params): array
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
            $msg = $json['message'] ?? $json['error'] ?? $response;
            throw new \RuntimeException("Error WA {$httpCode}: {$msg}");
        }

        // Algunas APIs devuelven HTTP 200 pero indican error en el body
        if (is_array($json) && (isset($json['error']) || ($json['success'] ?? true) === false)) {
            $msg = $json['message'] ?? $json['error'] ?? json_encode($json);
            throw new \RuntimeException("Error WA API: {$msg}");
        }

        // Loguear respuesta para debug (éxitos incluidos)
        \Illuminate\Support\Facades\Log::info('[WhatsAppService] Respuesta API', [
            'endpoint' => $endpoint,
            'httpCode' => $httpCode,
            'response' => $json ?? $response,
        ]);

        return is_array($json) ? $json : ['raw_response' => $response];
    }
}
