<?php
namespace App\UseCases\Crm\Interfaces;

interface GetConversationMessagesUseCaseInterface
{
    public function execute(int $conversationId): array;
}