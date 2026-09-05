<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class MetaWhatsAppService
{
    private bool   $enabled;
    private string $phoneNumberId;
    private string $accessToken;
    private string $apiVersion = 'v18.0';
    private ?int $companyId = null;

    public function __construct(?int $companyId = null)
    {
        $id = $companyId ?? getSessionCompanyId();
        $this->companyId = $id;
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
        if (!$this->hasOpenCustomerWindow($to)) return $this->closedWindowResponse();

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
        if (!$this->hasOpenCustomerWindow($to)) return $this->closedWindowResponse();

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

    // ── BOTONES INTERACTIVOS ────────────────────────
    /**
     * Envía un mensaje con botones de respuesta rápida
     * $buttons: array de ['id' => 'btn_id', 'title' => 'Texto del botón']
     */
    public function sendInteractiveButtons(string $to, string $bodyText, array $buttons, string $headerText = ''): array
    {
        if (!$this->isEnabled()) return ['success' => false, 'error' => 'Meta WhatsApp deshabilitado.'];
        if (!$this->hasOpenCustomerWindow($to)) return $this->closedWindowResponse();

        if (empty($buttons) || count($buttons) > 3) {
            return ['success' => false, 'error' => 'Máximo 3 botones permitidos'];
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $this->normalizePhone($to),
            'type'              => 'interactive',
            'interactive'       => [
                'type' => 'button',
                'body' => ['text' => $bodyText],
                'action' => [
                    'buttons' => array_map(static fn (array $btn) => [
                        'type'  => 'reply',
                        'reply' => [
                            'id'    => $btn['id'] ?? '',
                            'title' => $btn['title'] ?? $btn['label'] ?? '',
                        ],
                    ], $buttons),
                ],
            ],
        ];

        if (!empty($headerText)) {
            $payload['interactive']['header'] = [
                'type' => 'text',
                'text' => $headerText,
            ];
        }

        return $this->sendRequest($payload);
    }

    /**
     * Mensaje con botón que abre una URL en el navegador embebido de WhatsApp.
     *
     * Es lo más cerca que se llega hoy en Colombia a "pagar sin salir de WhatsApp":
     * el checkout se abre dentro de la app, no en el navegador del teléfono.
     * Solo funciona dentro de la ventana de 24 h; fuera de ella hace falta una
     * plantilla aprobada con botón de URL.
     */
    public function sendCtaUrl(
        string $to,
        string $bodyText,
        string $buttonText,
        string $url,
        string $headerText = '',
        string $footerText = ''
    ): array {
        if (!$this->isEnabled()) return ['success' => false, 'error' => 'Meta WhatsApp deshabilitado.'];
        if (!$this->hasOpenCustomerWindow($to)) return $this->closedWindowResponse();

        if (!str_starts_with(strtolower($url), 'https://')) {
            return ['success' => false, 'error' => 'La URL del botón debe usar HTTPS.'];
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $this->normalizePhone($to),
            'type'              => 'interactive',
            'interactive'       => [
                'type'   => 'cta_url',
                'body'   => ['text' => $bodyText],
                'action' => [
                    'name'       => 'cta_url',
                    'parameters' => [
                        // Meta corta el rótulo en 20 caracteres.
                        'display_text' => mb_substr($buttonText, 0, 20),
                        'url'          => $url,
                    ],
                ],
            ],
        ];

        if ($headerText !== '') {
            $payload['interactive']['header'] = ['type' => 'text', 'text' => mb_substr($headerText, 0, 60)];
        }

        if ($footerText !== '') {
            $payload['interactive']['footer'] = ['text' => mb_substr($footerText, 0, 60)];
        }

        return $this->sendRequest($payload);
    }

    /**
     * Envía un mensaje con menú de lista (solo en Meta, máx 10 opciones)
     */
    public function sendInteractiveList(string $to, string $bodyText, array $sections, string $buttonText = 'Opciones'): array
    {
        if (!$this->isEnabled()) return ['success' => false, 'error' => 'Meta WhatsApp deshabilitado.'];
        if (!$this->hasOpenCustomerWindow($to)) return $this->closedWindowResponse();

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $this->normalizePhone($to),
            'type'              => 'interactive',
            'interactive'       => [
                'type' => 'list',
                'body' => ['text' => $bodyText],
                'action' => [
                    'button' => $buttonText,
                    'sections' => $sections,
                ],
            ],
        ];

        return $this->sendRequest($payload);
    }

    public function sendInvoiceTemplate(string $to, array $parameters): array
    {
        if (!$this->isEnabled()) return ['success' => false, 'error' => 'Meta WhatsApp deshabilitado.'];

        return $this->sendRequest([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->normalizePhone($to),
            'type' => 'template',
            'template' => [
                'name' => 'envio_factura',
                'language' => ['code' => 'es_CO'],
                'components' => [[
                    'type' => 'body',
                    'parameters' => array_map(static fn (string $value): array => ['type' => 'text', 'text' => $value], $parameters),
                ]],
            ],
        ]);
    }

    public function isInvoiceTemplateApproved(): bool
    {
        if (!$this->isEnabled() || !$this->companyId) return false;

        $company = Company::find($this->companyId);
        if (!$company?->wa_business_id) return false;

        $response = Http::withToken($this->accessToken)
            ->get("https://graph.facebook.com/{$this->apiVersion}/{$company->wa_business_id}/message_templates", [
                'name' => 'envio_factura',
                'limit' => 20,
            ]);
        if ($response->failed()) return false;

        return collect($response->json('data') ?? [])
            ->contains(fn (array $template): bool => $template['name'] === 'envio_factura'
                && $template['language'] === 'es_CO'
                && $template['status'] === 'APPROVED');
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

    private function hasOpenCustomerWindow(string $phone): bool
    {
        if (!$this->companyId) return false;

        $normalizedPhone = $this->normalizePhone($phone);
        $lastCustomerMessage = DB::table('crm_messages as message')
            ->join('crm_conversations as conversation', 'conversation.id', '=', 'message.conversation_id')
            ->join('crm_customers as customer', 'customer.id', '=', 'conversation.customer_id')
            ->where('conversation.company_id', $this->companyId)
            ->where('conversation.provider', 'meta')
            ->where('message.sender_type', 'customer')
            ->whereRaw("REPLACE(REPLACE(REPLACE(customer.phone, '+', ''), ' ', ''), '-', '') = ?", [$normalizedPhone])
            ->orderByDesc('message.created_at')
            ->value('message.created_at');

        return $lastCustomerMessage !== null
            && \Carbon\Carbon::parse($lastCustomerMessage, 'UTC')->setTimezone('America/Bogota')->greaterThanOrEqualTo(now('America/Bogota')->subHours(24));
    }

    private function closedWindowResponse(): array
    {
        return [
            'success' => false,
            'error' => 'La ventana de atención de 24 horas de WhatsApp Meta está cerrada. Usa una plantilla aprobada para iniciar la conversación.',
            'code' => 'META_WINDOW_CLOSED',
        ];
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
