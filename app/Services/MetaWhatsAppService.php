<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MetaWhatsAppService
{
    private bool   $enabled;
    private string $phoneNumberId;
    private string $accessToken;
    private string $apiVersion = 'v18.0';

    public function __construct(?int $companyId = null)
    {
        $id = $companyId ?? getSessionCompanyId();
        $company = $id ? Company::find($id) : null;

        if ($company && $company->wa_provider === 'meta' && $company->wa_phone_number_id && $company->wa_access_token) {
            $this->enabled        = (bool) $company->whatsapp_enabled;
            $this->phoneNumberId  = $company->wa_phone_number_id;
            $this->accessToken    = $company->wa_access_token;
        } else {
            // Fallback a config global (.env)
            $this->enabled       = (bool) config('services.meta_whatsapp.enabled', false);
            $this->phoneNumberId = config('services.meta_whatsapp.phone_number_id', '');
            $this->accessToken   = config('services.meta_whatsapp.access_token', '');
        }
    }

    public function isEnabled(): bool
    {
        return $this->enabled && !empty($this->phoneNumberId) && !empty($this->accessToken);
    }

    // ── TEXTO ────────────────────────────────────────
    public function mensajeInformativo(string $to, string $body): array
    {
        if (!$this->isEnabled()) return ['success' => false, 'error' => 'Meta WhatsApp deshabilitado.'];

        return $this->sendRequest([
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $this->normalizePhone($to),
            'type'              => 'text',
            'text'              => ['body' => $body],
        ]);
    }

    // ── DOCUMENTO / PDF ──────────────────────────────
    public function sendDocument(string $to, string $documentUrl, string $filename, string $caption = ''): array
    {
        if (!$this->isEnabled()) return ['success' => false, 'error' => 'Meta WhatsApp deshabilitado.'];

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $this->normalizePhone($to),
            'type'              => 'document',
            'document'          => [
                'link'     => $documentUrl,
                'filename' => $filename,
            ],
        ];

        if ($caption) {
            $payload['document']['caption'] = $caption;
        }

        return $this->sendRequest($payload);
    }

    // ── DOCUMENTO / PDF (por contenido base64) ────────────────────────
    public function sendDocumentData(string $to, string $base64Content, string $filename, string $caption = '', string $mimetype = 'application/pdf'): array
    {
        if (!$this->isEnabled()) return ['success' => false, 'error' => 'Meta WhatsApp deshabilitado.'];

        // Meta requiere subir el archivo primero o usar un link público
        // Para base64, primero subimos el media a Meta
        $mediaId = $this->uploadMedia($base64Content, $mimetype);

        if (!$mediaId) {
            return ['success' => false, 'error' => 'No se pudo subir el documento a Meta.'];
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $this->normalizePhone($to),
            'type'              => 'document',
            'document'          => [
                'id'       => $mediaId,
                'filename' => $filename,
            ],
        ];

        if ($caption) {
            $payload['document']['caption'] = $caption;
        }

        return $this->sendRequest($payload);
    }

    // ── IMAGEN ───────────────────────────────────────
    public function sendImage(string $to, string $mediaUrl, string $caption = ''): array
    {
        if (!$this->isEnabled()) return ['success' => false, 'error' => 'Meta WhatsApp deshabilitado.'];

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $this->normalizePhone($to),
            'type'              => 'image',
            'image'             => ['link' => $mediaUrl],
        ];

        if ($caption) {
            $payload['image']['caption'] = $caption;
        }

        return $this->sendRequest($payload);
    }

    // ── VIDEO ────────────────────────────────────────
    public function sendVideo(string $to, string $mediaUrl, string $caption = ''): array
    {
        if (!$this->isEnabled()) return ['success' => false, 'error' => 'Meta WhatsApp deshabilitado.'];

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $this->normalizePhone($to),
            'type'              => 'video',
            'video'             => ['link' => $mediaUrl],
        ];

        if ($caption) {
            $payload['video']['caption'] = $caption;
        }

        return $this->sendRequest($payload);
    }

    // ── AUDIO ────────────────────────────────────────
    public function sendAudio(string $to, string $mediaUrl): array
    {
        if (!$this->isEnabled()) return ['success' => false, 'error' => 'Meta WhatsApp deshabilitado.'];

        return $this->sendRequest([
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $this->normalizePhone($to),
            'type'              => 'audio',
            'audio'             => ['link' => $mediaUrl],
        ]);
    }

    // ── NOTA DE VOZ ──────────────────────────────────
    public function sendVoice(string $to, string $mediaUrl): array
    {
        // En Meta, voice es tipo audio con PTT
        return $this->sendAudio($to, $mediaUrl);
    }

    // ── ENVÍO MASIVO / BATCH ─────────────────────────
    public function sendBulk(array $messages): array
    {
        if (!$this->isEnabled()) return ['queued' => 0, 'invalid' => count($messages), 'chunks' => 0];

        $totalQueued = 0;
        $totalInvalid = 0;

        foreach ($messages as $msg) {
            try {
                $type = $msg['type'] ?? 'text';
                $to   = $msg['number'] ?? '';
                $body = $msg['message'] ?? '';

                if (!$to || !$body) {
                    $totalInvalid++;
                    continue;
                }

                $this->mensajeInformativo($to, $body);
                $totalQueued++;

                // Rate limiting: máx 80 mensajes/minuto en Meta
                usleep(750000); // 0.75 segundos entre mensajes
            } catch (\Throwable $e) {
                Log::warning('[MetaWhatsAppService] Error en batch', ['error' => $e->getMessage()]);
                $totalInvalid++;
            }
        }

        return [
            'queued'  => $totalQueued,
            'invalid' => $totalInvalid,
            'chunks'  => 1,
        ];
    }

    // ── CORE ─────────────────────────────────────────
    private function sendRequest(array $payload): array
    {
        $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}/messages";

        $response = Http::withToken($this->accessToken)
            ->withHeaders([
                'Content-Type' => 'application/json',
            ])
            ->post($url, $payload);

        if ($response->failed()) {
            $error = $response->json('error.message') ?? $response->body();
            Log::error('[MetaWhatsAppService] Error API', [
                'status'  => $response->status(),
                'error'   => $error,
                'payload' => $payload,
            ]);
            throw new \RuntimeException("Error Meta WA {$response->status()}: {$error}");
        }

        Log::info('[MetaWhatsAppService] Mensaje enviado', [
            'to'       => $payload['to'] ?? null,
            'type'     => $payload['type'] ?? null,
            'response' => $response->json(),
        ]);

        return $response->json();
    }

    /**
     * Sube un archivo base64 a Meta para obtener un media ID.
     */
    private function uploadMedia(string $base64Content, string $mimeType): ?string
    {
        try {
            $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}/media";

            $response = Http::withToken($this->accessToken)
                ->attach('file', base64_decode($base64Content), 'file', ['Content-Type' => $mimeType])
                ->post($url, [
                    'messaging_product' => 'whatsapp',
                    'type'              => $mimeType,
                ]);

            if ($response->successful()) {
                return $response->json('id');
            }

            Log::error('[MetaWhatsAppService] Error subiendo media', [
                'response' => $response->json(),
            ]);

            return null;
        } catch (\Throwable $e) {
            Log::error('[MetaWhatsAppService] Excepción subiendo media', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Normaliza el número de teléfono para Meta (sin +, solo números).
     */
    private function normalizePhone(string $phone): string
    {
        return preg_replace('/[^0-9]/', '', $phone);
    }
}
