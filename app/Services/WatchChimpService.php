<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WatchChimpService
{
    private string $baseUrl;
    private string $apiToken;
    private string $phoneNumberId;

    public function __construct()
    {
        $this->baseUrl = "https://app.whatchimp.com/api/v1/whatsapp/send";
        $this->apiToken = config('services.whatchimp.token') ?? '';
        $this->phoneNumberId = config('services.whatchimp.phone_id') ?? '';

        if (!$this->apiToken || !$this->phoneNumberId) {
            throw new \Exception('WatchChimp config missing');
        }
    }

    /**
     * Enviar texto normal
     */
    public function sendText(string $phone, string $message): array
    {
        return $this->send($phone, $message);
    }

    /**
     * Responder a un mensaje específico
     */
    public function replyText(string $phone, string $message, string $replyToMessageId): array
    {
        return $this->send($phone, $message, $replyToMessageId);
    }

    public function sendTemplate(string $phone, string $templateId, array $variables = []): array
    {
        try {

            $payload = [
                'apiToken' => $this->apiToken,
                'phone_number_id' => $this->phoneNumberId,
                'template_id' => $templateId,
                'phone_number' => $phone,
            ];

            $index = 1;
            foreach ($variables as $key => $value) {
                $payload["templateVariable-{$key}-{$index}"] = $value;
                $index++;
            }

            $response = Http::asForm()->post("{$this->baseUrl}/template", $payload);

            return $response->json();

        } catch (\Throwable $e) {
            return [
                'status' => 0,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Método interno de envío
     */
    private function send(string $phone, string $message, ?string $replyToMessageId = null): array
    {
        try {

            $payload = [
                'apiToken' => $this->apiToken,
                'phone_number_id' => $this->phoneNumberId,
                'phone_number' => $phone,
                'message' => $message
            ];

            // si queremos responder a un mensaje
            if ($replyToMessageId) {
                $payload['reply_to'] = $replyToMessageId;
            }

            $response = Http::asForm()->post($this->baseUrl, $payload);

            Log::info('[WATCHCHIMP SEND]', [
                'payload' => $payload,
                'response' => $response->json()
            ]);

            return $response->json();

        } catch (\Throwable $e) {

            Log::error('[WATCHCHIMP ERROR]', [
                'error' => $e->getMessage()
            ]);

            return [
                'status' => 0,
                'error' => $e->getMessage()
            ];
        }
    }
}