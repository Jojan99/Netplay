<?php

namespace App\UseCases\Crm;

use App\Constants\ApiResponseConstants;
use App\Repositories\Interfaces\ConversationRepositoryInterface;
use App\UseCases\Crm\Interfaces\GetCrmAgentsUseCaseInterface;
use App\UseCases\Crm\Interfaces\TransferConversationUseCaseInterface;
use Illuminate\Support\Collection;

class TransferConversationUseCase implements TransferConversationUseCaseInterface
{
    public function __construct(
        private ConversationRepositoryInterface $repository
    ) {}

    public function execute(
        int $conversationId,
        int $toUserId,
        ?string $reason = null
    ): array {

        error_log('llega metodo hasta aca');
        error_log(getSessionUserProfileId());
        error_log(getSessionUserId());
        // 1. conversación
        $conversation = $this->repository->find($conversationId);

        if (!$conversation) {
            return [
                'message' => 'Conversación no encontrada',
                'status' => 1,
                'data' => ApiResponseConstants::DATA_NULL
            ];
        }

        if ($conversation->status === 'closed') {
             return [
                'message' => 'No se puede transferir una conversación cerrada',
                'status' => 1,
                'data' => ApiResponseConstants::DATA_NULL
            ];
        }

        // 2. último agente
        $current = $this->repository->getLastAssignment($conversationId);

        if ($current && $current->to_user_id === $toUserId) {
                return [
                'message' => 'La conversación ya está asignada a este agente',
                'status' => 1,
                'data' => ApiResponseConstants::DATA_NULL
            ];
        }

        // 3. insertar historial
        $this->repository->insertAssignment([
            'conversation_id' => $conversationId,
            'from_user_id'    => $current?->to_user_id,
            'to_user_id'      => $toUserId,
            'reason'          => $reason,
        ]);

        // 4. actualizar asignado actual (opcional si lo usas)
        $this->repository->updateAssignedUser(
            $conversationId,
            $toUserId
        );

        return [
            'conversation_id' => $conversationId,
            'from_user_id' => $current?->to_user_id,
            'to_user_id' => $toUserId
        ];
    }
}
