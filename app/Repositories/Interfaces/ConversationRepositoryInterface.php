<?php

namespace App\Repositories\Interfaces;

use App\Models\CrmMessage;
use Illuminate\Support\Collection;


interface ConversationRepositoryInterface
{
    /**
     * Inbox de conversaciones con:
     * - cliente
     * - último mensaje
     * - estado/prioridad
     * - agente asignado (id)
     */
    public function getInbox(array $filters = []): array;
    public function getByConversation(int $conversationId): array;
    public function getOrCreateConversationByPhone(string $phone, string $names): int;
    public function getConversationStatus(string $phone): bool;
    public function storeMessage(array $data): CrmMessage;
    public function find(int $id);
    public function getActiveConversationByPhone(string $phone): ?object;
    public function getPhoneByConversationId(int $conversationId): ?string;
public function isFirstMessage(int $conversationId): bool;
    public function updateStatus(int $id, string $status): void;

    public function createMessage(array $data): CrmMessage;

    public function markMessageAsSent(int $messageId): void;

    public function getAgentsWithLoad(): Collection;

    public function getLastAssignment(int $conversationId);

    public function insertAssignment(array $data): void;
    public function getActiveConversationsByAgent(): Collection;

    public function updateAssignedUser(
        int $conversationId,
        int $userId
    ): void;
}
