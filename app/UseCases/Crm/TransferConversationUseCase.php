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

        // La conversación y el agente destino deben ser de la empresa en sesión:
        // no se puede pasar un chat a un usuario de otra empresa.
        $companyId = (int) getSessionCompanyId();
        if (!$companyId || (int) $conversation->company_id !== $companyId) {
            return [
                'message' => 'Conversación no encontrada',
                'status' => 1,
                'data' => ApiResponseConstants::DATA_NULL
            ];
        }

        $destinoEsDeLaEmpresa = \Illuminate\Support\Facades\DB::table('users')
            ->where('id', $toUserId)
            ->where('company_id', $companyId)
            ->exists();
        if (!$destinoEsDeLaEmpresa) {
            return [
                'message' => 'El agente destino no pertenece a la empresa',
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
