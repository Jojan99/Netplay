<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\WatchChimpService;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Log;

/**
 * Job para enviar mensajes de WhatsApp con delay controlado.
 * Puede usarse con cualquier driver de queue (database, redis, etc.)
 * para procesar envíos en background y evitar timeouts.
 */
class SendWhatsAppInvoiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60; // segundos entre reintentos

    /**
     * Constructor
     *
     * @param string $phone Número de teléfono destino
     * @param string $type Tipo de envío: 'template' | 'text' | 'document' | 'image'
     * @param array $payload Datos del envío según el tipo
     * @param int|null $companyId ID de empresa para WhatsAppService (opcional)
     * @param int $delaySeconds Segundos de delay antes de procesar este job
     */
    public function __construct(
        public string $phone,
        public string $type,
        public array $payload,
        public ?int $companyId = null,
        public int $delaySeconds = 0
    ) {
        // Si hay delay, programar el job para más tarde
        if ($delaySeconds > 0) {
            $this->delay(now()->addSeconds($delaySeconds));
        }
    }

    public function handle(): void
    {
        try {
            Log::info('[WA_JOB] Iniciando envío', [
                'phone' => $this->phone,
                'type' => $this->type,
                'company_id' => $this->companyId,
            ]);

            match ($this->type) {
                'template' => $this->sendTemplate(),
                'text' => $this->sendText(),
                'document' => $this->sendDocument(),
                'image' => $this->sendImage(),
                default => throw new \Exception("Tipo de envío desconocido: {$this->type}"),
            };

            Log::info('[WA_JOB] Envío completado', [
                'phone' => $this->phone,
                'type' => $this->type,
            ]);

        } catch (\Throwable $e) {
            Log::error('[WA_JOB] Error en envío', [
                'phone' => $this->phone,
                'type' => $this->type,
                'error' => $e->getMessage(),
            ]);
            throw $e; // Re-lanzar para que Laravel maneje reintentos
        }
    }

    private function sendTemplate(): void
    {
        $service = new WatchChimpService();
        $response = $service->sendTemplate(
            $this->phone,
            $this->payload['template_id'],
            $this->payload['variables'] ?? []
        );

        if (($response['status'] ?? 0) != 1) {
            throw new \Exception('Template failed: ' . json_encode($response));
        }
    }

    private function sendText(): void
    {
        $wa = $this->companyId
            ? new WhatsAppService($this->companyId)
            : new WhatsAppService();

        $wa->mensajeInformativo($this->phone, $this->payload['message']);
    }

    private function sendDocument(): void
    {
        $wa = $this->companyId
            ? new WhatsAppService($this->companyId)
            : new WhatsAppService();

        $wa->sendDocument(
            $this->phone,
            $this->payload['url'],
            $this->payload['filename'],
            $this->payload['caption'] ?? ''
        );
    }

    private function sendImage(): void
    {
        $wa = $this->companyId
            ? new WhatsAppService($this->companyId)
            : new WhatsAppService();

        $wa->sendImage(
            $this->phone,
            $this->payload['url'],
            $this->payload['caption'] ?? ''
        );
    }
}
