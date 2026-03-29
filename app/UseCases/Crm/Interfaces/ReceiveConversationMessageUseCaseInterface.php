<?php
namespace App\UseCases\Crm\Interfaces;

interface ReceiveConversationMessageUseCaseInterface
{
    public function execute(array $payload): array;
}
