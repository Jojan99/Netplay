<?php
namespace App\UseCases\Crm;

use App\Repositories\ConversationRepository;
use App\Events\ConversationClosedEvent;
use App\UseCases\Crm\Interfaces\CloseConversationUseCaseInterface;
use App\Services\WhatsAppService;

class CloseConversationUseCase implements CloseConversationUseCaseInterface
{
    public function __construct(
        private ConversationRepository $conversationRepository
    ) {
        $this->whatsAppService = new WhatsAppService();

    }

    public function execute(int $conversationId): void
    {
        $conversation = $this->conversationRepository->find($conversationId);
        if ($conversation->status === 'closed') {
            return; // idempotente
        }

        $phone = $this->conversationRepository
        ->getPhoneByConversationId($conversationId);

        $this->conversationRepository->updateStatus(
            $conversationId,
            'closed'
        );

        broadcast(new ConversationClosedEvent($conversationId));


        $closingMessage = "✨ Conversación finalizada.\n\n🙏 Fue un gusto atenderte.\n\nSi más adelante necesitas soporte o tienes alguna consulta, estaremos encantados de ayudarte nuevamente.\n\n📡 Gracias por confiar en Netplay.";


         $this->whatsAppService->mensajeInformativo(
            $phone,
            $closingMessage
        );

    }
}
