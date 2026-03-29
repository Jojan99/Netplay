<?php
namespace App\UseCases\Crm\Interfaces;

interface CloseConversationUseCaseInterface
{
      public function execute(int $conversationId): void;
}