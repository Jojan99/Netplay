<?php
namespace App\UseCases\Crm;

use App\Repositories\ConversationRepository;
use App\Events\ConversationClosedEvent;
use App\UseCases\Crm\Interfaces\CloseConversationUseCaseInterface;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Log;

class CloseConversationUseCase implements CloseConversationUseCaseInterface
{
    public function __construct(
        private ConversationRepository $conversationRepository
    ) {}

    public function execute(int $conversationId): void
    {
        $conversation = $this->conversationRepository->find($conversationId);

        if (!$conversation || $conversation->status === 'closed') {
            return; // idempotente
        }

        $phone = $this->conversationRepository
            ->getPhoneByConversationId($conversationId);

        $this->conversationRepository->updateStatus(
            $conversationId,
            'closed'
        );

        broadcast(new ConversationClosedEvent($conversationId));

        $closingMessage = "✨ Conversación finalizada.\n\n🙏 Fue un gusto atenderte.\n\nSi más adelante necesitas soporte o tienes alguna consulta, estaremos encantados de ayudarte nuevamente.\n\n📡 Gracias por confiar en " . \App\Support\CrmSettings::companyName((int) $conversation->company_id) . ".";

        try {
            // El aviso sale por el MISMO canal de la conversación.
            //
            // Antes se instanciaba WhatsAppService sin argumentos, en el
            // constructor: sin empresa y sin canal, con lo que caía en el
            // proveedor por defecto. Cerrar un chat de WhatsApp Web mandaba el
            // mensaje por la API de Meta, a un número que nunca escribió por
            // ese canal: eso cae fuera de la ventana de 24 h y es motivo de
            // bloqueo de la cuenta de WhatsApp Business.
            // Y por la MISMA línea: el cliente escribió a un número de la
            // empresa, la despedida no puede llegarle desde otro.
            WhatsAppService::paraConversacion($conversation)
                ->mensajeInformativo($phone, $closingMessage);
        } catch (\Throwable $e) {
            // El cierre ya quedó guardado. Si el envío falla, la conversación
            // igual queda cerrada en vez de dejar el pedido a medias y que el
            // agente la siga viendo "en curso".
            Log::warning('[Cierre de conversación] No se pudo enviar el aviso', [
                'conversation_id' => $conversationId,
                'provider'        => $conversation->provider ?? null,
                'error'           => $e->getMessage(),
            ]);
        }
    }
}
