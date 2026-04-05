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

    // ── Notas ──────────────────────────────────────────────────────────────
    public function getNotes(int $conversationId): array;
    public function addNote(int $conversationId, int $userId, string $content): array;
    public function deleteNote(int $noteId): void;

    // ── Etiquetas ──────────────────────────────────────────────────────────
    public function getLabels(int $companyId): array;
    public function createLabel(int $companyId, string $name, string $color): array;
    public function deleteLabel(int $labelId): void;
    public function getConversationLabels(int $conversationId): array;
    public function addConversationLabel(int $conversationId, int $labelId): void;
    public function removeConversationLabel(int $conversationId, int $labelId): void;

    // ── Prioridad ──────────────────────────────────────────────────────────
    public function updatePriority(int $conversationId, string $priority): void;

    // ── Dashboard métricas ─────────────────────────────────────────────────
    public function getDashboardMetrics(int $companyId): array;

    // ── Broadcast ──────────────────────────────────────────────────────────
    public function getCustomersForBroadcast(int $companyId): array;

    // ── Contacto / nueva conversación ─────────────────────────────────────
    public function createConversationFromPhone(string $phone, string $name, int $companyId): int;

    // ── Estado de servicio del cliente ────────────────────────────────────
    public function getServiceStatusByPhone(string $phone): ?array;

    // ── Stickers ──────────────────────────────────────────────────────────
    public function getStickers(int $companyId): array;
    public function saveSticker(int $companyId, string $mediaUrl, ?string $name): array;
    public function deleteSticker(int $stickerId): void;
}
