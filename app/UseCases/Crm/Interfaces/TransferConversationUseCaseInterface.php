<?php
namespace App\UseCases\Crm\Interfaces;
interface TransferConversationUseCaseInterface
{
    public function execute(
        int $conversationId,
        int $toUserId,
        ?string $reason = null
    ): array;
}
