<?php

namespace App\UseCases\Crm;

use App\Constants\ApiResponseConstants;
use App\Events\InboxMessageEvent;
use App\Events\NewMessageEvent;
use App\Repositories\Interfaces\ConversationRepositoryInterface;
use App\Services\WhatsAppService;
use App\UseCases\Crm\Interfaces\SendMessageUseCaseInterface;
use App\Events\InboxUpdatedEvent;
use App\Services\WatchChimpService;

class SendMessageUseCase implements SendMessageUseCaseInterface
{
    public function __construct(
        private ConversationRepositoryInterface $conversationRepository
    ) {}

    public function execute(
    int $conversationId,
    string $content,
    ?int $agentId = null
) {
    // 🔥 USUARIO REAL (SIEMPRE)
    $agentId = $agentId ?? getSessionUserId();

    if (!$agentId) {
            return [
                'message' => 'Usuario no autenticado',
                'status' => 1,
                'data' => ApiResponseConstants::DATA_NULL
            ];
    }

    // 🔍 obtener conversación
    $conversation = $this->conversationRepository->find($conversationId);

    if (!$conversation) {
           return [
                'message' => 'Conversación no encontrada',
                'status' => 1,
                'data' => ApiResponseConstants::DATA_NULL
            ];
    }

    // 🔥 AUTO-ASIGNACIÓN SI ES NEW
    if ($conversation->status === 'new') {

        $this->conversationRepository->updateAssignedUser(
            $conversation->id,
            $agentId
        );

        $this->conversationRepository->updateStatus(
            $conversation->id,
            'in_progress'
        );

        $this->conversationRepository->insertAssignment([
            'conversation_id' => $conversation->id,
            'from_user_id'    => null,
            'to_user_id'      => $agentId,
            'reason'          => 'auto_assign_first_reply'
        ]);

    }
        $newStatus = 'in_progress';


    // 4️⃣ guardar mensaje
    $message = $this->conversationRepository->createMessage([
        'conversation_id' => $conversation->id,
        'sender_user_id'  => $agentId,
        'sender_type'     => 'agent',
        'content'         => $content,
        'message_type'    => 'text',
    ]);

    // 5️⃣ broadcast chat
    broadcast(new NewMessageEvent($message, $conversation->id));

    // 6️⃣ broadcast inbox
    // 🔥 Broadcast inbox con estado final real
        broadcast(new InboxUpdatedEvent(
            $conversation->id,
            $newStatus
        ));


        // $whats = new WatchChimpService();

        //     $whats->replyText(
        //     $conversation->phone,
        //     $content,
        //     "wamid.HBgMNTczMTQ0NTgwODQ0FQIAEhgUM0ExMkJBRjlCRjJDQjIzNEEwMUYA"
        // );

    //WhatsApp
    // Misma empresa, dos mecanismos posibles (Meta API o Netplay WhatsApp): se debe usar
    // el provider real de ESTA conversación, nunca un valor global de la empresa.
    $whatsAppService = new WhatsAppService(
        $conversation->company_id,
        false,
        $conversation->provider ?? 'netplay'
    );

    $whats = $whatsAppService->mensajeInformativo(
        $conversation->phone,
        $content,
    );

    return $whats;
}
}
