<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Facades\Cache;
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

    /**
     * Número visible del bot, para armar enlaces wa.me que devuelvan al chat.
     *
     * No se guarda en la empresa, así que se le pregunta a Meta y se cachea:
     * cambia casi nunca y no vale una llamada por visita.
     */
    public function businessPhoneNumber(): ?string
    {
        if (!$this->isEnabled()) return null;

        return Cache::remember("wa:display_phone:{$this->phoneNumberId}", 86400, function (): ?string {
            try {
                $response = Http::withToken($this->accessToken)
                    ->timeout(10)
                    ->get("https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}", [
                        'fields' => 'display_phone_number',
                    ]);

                if ($response->failed()) return null;

                $digits = preg_replace('/\D+/', '', (string) $response->json('display_phone_number'));

                return strlen($digits) >= 10 ? $digits : null;
            } catch (\Throwable $e) {
                Log::warning('[MetaWhatsAppService] No se pudo leer el número del bot', ['error' => $e->getMessage()]);
                return null;
            }
        });
    }

    // ── TEXTO ────────────────────────────────────────
    public function mensajeInformativo(string $to, string $body, ?array $quoted = null): array
    {
        if (!$this->isEnabled()) return ['success' => false, 'error' => 'Meta WhatsApp deshabilitado.'];
        if (!$this->hasOpenCustomerWindow($to)) return $this->closedWindowResponse();

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            ...$this->recipientField($to),
            'type'              => 'text',
            'text'              => ['body' => $body],
        ];
        // Responder citando: Meta usa context.message_id con el wamid del original
        if (!empty($quoted['id']) && str_starts_with((string)$quoted['id'], 'wamid.')) {
            $payload['context'] = ['message_id' => $quoted['id']];
        }
        return $this->sendRequest($payload);
    }

    public function sendReaction(string $to, string $targetExternalId, bool $targetFromMe, string $emoji): array
    {
        if (!$this->isEnabled()) return ['success' => false, 'error' => 'Meta WhatsApp deshabilitado.'];
        return $this->sendRequest(['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', ...$this->recipientField($to), 'type' => 'reaction',
            'reaction' => ['message_id' => $targetExternalId, 'emoji' => $emoji]]);
    }

    public function sendSticker(string $to, string $stickerUrl, ?array $quoted = null): array
    {
        if (!$this->isEnabled()) return ['success' => false, 'error' => 'Meta WhatsApp deshabilitado.'];
        if (!$this->hasOpenCustomerWindow($to)) return $this->closedWindowResponse();
        $payload = ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', ...$this->recipientField($to), 'type' => 'sticker', 'sticker' => ['link' => $stickerUrl]];
        if (!empty($quoted['id']) && str_starts_with((string)$quoted['id'], 'wamid.')) $payload['context'] = ['message_id' => $quoted['id']];
        return $this->sendRequest($payload);
    }

    public function sendLocation(string $to, float $latitude, float $longitude, ?string $name = null, ?string $address = null, ?array $quoted = null): array
    {
        if (!$this->isEnabled()) return ['success' => false, 'error' => 'Meta WhatsApp deshabilitado.'];
        if (!$this->hasOpenCustomerWindow($to)) return $this->closedWindowResponse();
        $payload = ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', ...$this->recipientField($to), 'type' => 'location',
            'location' => array_filter(['latitude' => $latitude, 'longitude' => $longitude, 'name' => $name, 'address' => $address], fn($v) => $v !== null)];
        if (!empty($quoted['id']) && str_starts_with((string)$quoted['id'], 'wamid.')) $payload['context'] = ['message_id' => $quoted['id']];
        return $this->sendRequest($payload);
    }

    public function sendContact(string $to, ?string $contactName, string $contactPhone, ?array $quoted = null): array
    {
        if (!$this->isEnabled()) return ['success' => false, 'error' => 'Meta WhatsApp deshabilitado.'];
        if (!$this->hasOpenCustomerWindow($to)) return $this->closedWindowResponse();
        $digits = preg_replace('/\D/', '', $contactPhone);
        $payload = ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', ...$this->recipientField($to), 'type' => 'contacts',
            'contacts' => [['name' => ['formatted_name' => $contactName ?: $digits, 'first_name' => $contactName ?: $digits], 'phones' => [['phone' => '+' . $digits, 'type' => 'CELL', 'wa_id' => $digits]]]]];
        if (!empty($quoted['id']) && str_starts_with((string)$quoted['id'], 'wamid.')) $payload['context'] = ['message_id' => $quoted['id']];
        return $this->sendRequest($payload);
    }

    // ── DOCUMENTO / PDF ──────────────────────────────
    public function sendDocument(string $to, string $documentUrl, string $filename, string $caption = ''): array
    {
        if (!$this->isEnabled()) return ['success' => false, 'error' => 'Meta WhatsApp deshabilitado.'];
        if (!$this->hasOpenCustomerWindow($to)) return $this->closedWindowResponse();

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            ...$this->recipientField($to),
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
            ...$this->recipientField($to),
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
            ...$this->recipientField($to),
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
            ...$this->recipientField($to),
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
            ...$this->recipientField($to),
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
        if (empty($buttons) || count($buttons) > 3) {
            return ['success' => false, 'error' => 'Máximo 3 botones permitidos'];
        }

        // Meta corta en 20 caracteres y exige títulos distintos entre sí. Si se
        // repiten rechaza el mensaje entero con un "(#131009) Parameter value is
        // not valid" que no dice cuál es el problema. Mejor detectarlo aquí.
        $titles = [];
        foreach ($buttons as $i => $btn) {
            $title = trim(mb_substr((string) ($btn['title'] ?? $btn['label'] ?? ''), 0, 20));

            if ($title === '') {
                return ['success' => false, 'error' => "El botón #" . ($i + 1) . " no tiene texto."];
            }

            if (in_array($title, $titles, true)) {
                return ['success' => false, 'error' => "Dos botones dicen \"{$title}\"; Meta los exige distintos."];
            }

            $titles[] = $title;
        }

        if (!$this->hasOpenCustomerWindow($to)) return $this->closedWindowResponse();

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            ...$this->recipientField($to),
            'type'              => 'interactive',
            'interactive'       => [
                'type' => 'button',
                'body' => ['text' => $bodyText],
                'action' => [
                    'buttons' => array_map(static fn (array $btn, string $title) => [
                        'type'  => 'reply',
                        'reply' => [
                            'id'    => $btn['id'] ?? '',
                            'title' => $title,
                        ],
                    ], $buttons, $titles),
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
            ...$this->recipientField($to),
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
            ...$this->recipientField($to),
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

    /**
     * @param array<int, string> $urlButtons Valor de cada botón de URL con
     *        variable, indexado por su posición en la plantilla.
     */
    public function sendInvoiceTemplate(string $to, array $parameters, array $urlButtons = []): array
    {
        if (!$this->isEnabled()) return ['success' => false, 'error' => 'Meta WhatsApp deshabilitado.'];

        return $this->sendTemplate($to, $this->invoiceTemplateName(), $parameters, 'es_CO', $urlButtons);
    }

    /**
     * Qué plantilla se usa para mandar la factura.
     *
     * La elige el panel. El nombre estaba escrito a fuego, así que cambiar a
     * una versión corregida obligaba a tocar código.
     */
    public function invoiceTemplateName(): string
    {
        $elegida = \App\Models\WaTemplateBinding::where('company_id', $this->companyId)
            ->where('event', 'envio_factura')
            ->value('template_name');

        return $elegida ?: 'envio_factura';
    }

    /**
     * Envía una plantilla aprobada por Meta.
     *
     * Es el único camino cuando la ventana de 24 h está cerrada, es decir
     * cuando el cliente no nos ha escrito recientemente. Meta rechaza saltos de
     * línea y tabulaciones dentro de los parámetros, así que se limpian.
     */
    /**
     * @param array<int, string> $urlButtons Valor de cada botón de URL con
     *        variable, indexado por la posición del botón en la plantilla.
     */
    public function sendTemplate(
        string $to,
        string $name,
        array $parameters = [],
        string $language = 'es_CO',
        array $urlButtons = []
    ): array {
        if (!$this->isEnabled()) return ['success' => false, 'error' => 'Meta WhatsApp deshabilitado.'];

        $components = [];
        if ($parameters !== []) {
            $components[] = [
                'type' => 'body',
                'parameters' => array_map(
                    static fn ($value): array => [
                        'type' => 'text',
                        'text' => trim(preg_replace('/\s+/u', ' ', (string) $value)),
                    ],
                    array_values($parameters)
                ),
            ];
        }

        // Botones de URL con variable: es lo que convierte un "Pagar ahora" que
        // lleva a la página de inicio en uno que abre el cobro de ese cliente.
        // Cada uno va con su índice, que es como Meta los numera.
        foreach ($urlButtons as $indice => $valor) {
            if ($valor === null || $valor === '') continue;

            $components[] = [
                'type'     => 'button',
                'sub_type' => 'url',
                'index'    => (string) $indice,
                'parameters' => [['type' => 'text', 'text' => (string) $valor]],
            ];
        }

        return $this->sendRequest([
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            ...$this->recipientField($to),
            'type'              => 'template',
            'template'          => array_filter([
                'name'       => $name,
                'language'   => ['code' => $language],
                'components' => $components ?: null,
            ]),
        ]);
    }

    /** ¿Meta ya aprobó esta plantilla? */
    public function isTemplateApproved(string $name, string $language = 'es_CO'): bool
    {
        if (!$this->isEnabled() || !$this->companyId) return false;

        $company = Company::find($this->companyId);
        if (!$company?->wa_business_id) return false;

        $response = Http::withToken($this->accessToken)
            ->get("https://graph.facebook.com/{$this->apiVersion}/{$company->wa_business_id}/message_templates", [
                'name'  => $name,
                'limit' => 20,
            ]);
        if ($response->failed()) return false;

        return collect($response->json('data') ?? [])
            ->contains(fn (array $t): bool => ($t['name'] ?? null) === $name
                && ($t['language'] ?? null) === $language
                && ($t['status'] ?? null) === 'APPROVED');
    }

    /**
     * Qué botones de URL de la plantilla llevan variable, y con qué texto.
     *
     * Meta numera los botones y cada parámetro hay que mandarlo con su índice.
     * Se consulta la plantilla real en vez de suponerlo: mandarle un parámetro
     * a un botón de URL fija revienta el envío entero.
     *
     * @return array<int, string> índice => texto del botón (para saber cuál es
     *         el de pagar y cuál el de ver la factura).
     */
    public function dynamicUrlButtons(string $name, string $language = 'es_CO'): array
    {
        $clave = "meta:btns_url:{$this->companyId}:{$name}:{$language}";

        try {
            $guardado = Cache::get($clave);
            if (is_array($guardado)) {
                return $guardado;
            }
        } catch (\Throwable $e) {
            // Sin caché se consulta cada vez.
        }

        $botones = $this->buscarBotonesDeUrl($name, $language);

        try {
            Cache::put($clave, $botones, now()->addHour());
        } catch (\Throwable $e) {
            // No poder cachearlo no es motivo para no enviar.
        }

        return $botones;
    }

    /** @return array<int, string> */
    private function buscarBotonesDeUrl(string $name, string $language): array
    {
        if (!$this->isEnabled() || !$this->companyId) return [];

        $company = Company::find($this->companyId);
        if (!$company?->wa_business_id) return [];

        try {
            $response = Http::withToken($this->accessToken)
                ->timeout(15)
                ->get("https://graph.facebook.com/{$this->apiVersion}/{$company->wa_business_id}/message_templates", [
                    'name'  => $name,
                    'limit' => 20,
                ]);
        } catch (\Throwable $e) {
            return [];
        }

        if ($response->failed()) return [];

        $plantilla = collect($response->json('data') ?? [])
            ->first(fn (array $t): bool => ($t['name'] ?? null) === $name && ($t['language'] ?? null) === $language);

        if (!$plantilla) return [];

        $botones = collect($plantilla['components'] ?? [])
            ->first(fn (array $c): bool => ($c['type'] ?? null) === 'BUTTONS')['buttons'] ?? [];

        $conVariable = [];

        foreach ($botones as $i => $boton) {
            if (($boton['type'] ?? null) !== 'URL' || !str_contains((string) ($boton['url'] ?? ''), '{{')) {
                continue;
            }

            $conVariable[$i] = (string) ($boton['text'] ?? '');

            // Una plantilla puede quedar apuntando a un dominio que no es
            // nuestro —pasa al escribir la URL a mano— y entonces el cliente
            // toca "Pagar ahora" y cae en cualquier parte. El envío igual sale,
            // porque Meta exige el parámetro, pero queda dicho en el log.
            $this->avisarSiApuntaFuera($name, (string) $boton['url']);
        }

        return $conVariable;
    }

    private function avisarSiApuntaFuera(string $plantilla, string $url): void
    {
        $destino = parse_url($url, PHP_URL_HOST);
        $propio  = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (!$destino || !$propio) return;

        // www.netplay.com.co y netplay.com.co son el mismo sitio.
        $normalizar = static fn (string $h): string => preg_replace('/^www\./', '', mb_strtolower($h));

        if ($normalizar($destino) === $normalizar($propio)) return;

        Log::warning('[Meta] El botón de una plantilla apunta fuera del sitio', [
            'plantilla' => $plantilla,
            'url'       => $url,
            'esperado'  => $propio,
        ]);
    }

    public function isInvoiceTemplateApproved(): bool
    {
        return $this->isTemplateApproved($this->invoiceTemplateName(), 'es_CO');
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
    /**
     * ¿Meta aceptó el mensaje?
     *
     * La API no devuelve ningún "success": responde con el id del mensaje, o
     * lanza excepción. Leerlo mal hace que un envío correcto se registre como
     * fallido, así que la lectura vive en un solo sitio.
     */
    public static function accepted(array $respuesta): bool
    {
        return self::messageIdOf($respuesta) !== null;
    }

    /** El wamid que devolvió Meta, si lo hay. */
    public static function messageIdOf(array $respuesta): ?string
    {
        $id = $respuesta['messages'][0]['id'] ?? null;

        return $id ? (string) $id : null;
    }

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
            // Una identidad de usuario no tiene número nacional que comparar:
            // se busca tal cual. Para teléfonos se comparan los diez últimos
            // dígitos, porque `user_data.phone` guarda "3245127869" y el CRM
            // "+573245127869"; comparar las cadenas completas daba siempre
            // "ventana cerrada" para cualquier aviso que saliera de nuestro lado.
            ->when(
                self::isUserIdentity($normalizedPhone),
                fn ($query) => $query->where('customer.phone', $normalizedPhone),
                fn ($query) => $query->whereRaw(
                    "RIGHT(REPLACE(REPLACE(REPLACE(customer.phone, '+', ''), ' ', ''), '-', ''), 10) = ?",
                    [substr($normalizedPhone, -10)]
                )
            )
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
    /**
     * Destinatario tal como lo espera Meta.
     *
     * Con los nombres de usuario de WhatsApp hay cuentas que no tienen teléfono
     * y se identifican como "CO.1559353791887122". Eso viaja intacto: quitarle
     * los caracteres no numéricos lo convertiría en un número inexistente.
     */
    private function normalizePhone(string $phone): string
    {
        $phone = trim($phone);

        return self::isUserIdentity($phone) ? $phone : preg_replace('/[^0-9]/', '', $phone);
    }

    /**
     * Campo con el que Meta identifica al destinatario.
     *
     * Un teléfono va en `to`; una identidad de usuario va en `recipient`. No es
     * intercambiable: mandando la identidad en `to`, Meta le quita el prefijo,
     * la trata como si fuera un número, acepta la petición con HTTP 200 y
     * después falla la entrega con el error 131026 "Message undeliverable".
     *
     * @return array<string, string>
     */
    private function recipientField(string $to): array
    {
        $destino = $this->normalizePhone($to);

        return self::isUserIdentity($destino)
            ? ['recipient' => $destino]
            : ['to' => $destino];
    }

    /** ¿Es una identidad de usuario de WhatsApp en vez de un teléfono? */
    public static function isUserIdentity(string $value): bool
    {
        return (bool) preg_match('/^[A-Z]{2}\.\d+$/', trim($value));
    }
}
