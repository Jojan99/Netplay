<?php


namespace App\UseCases\Crm;

use App\Constants\ApiResponseConstants;
use App\Repositories\Crm\MessageRepositoryInterface;
use App\Repositories\Interfaces\ConversationRepositoryInterface;
use App\UseCases\Crm\Interfaces\GetConversationMessagesUseCaseInterface;

class GetConversationMessagesUseCase implements GetConversationMessagesUseCaseInterface
{
    public function __construct(
        private ConversationRepositoryInterface $repository
    ) {}

    public function execute(int $conversationId): array
    {
        $messages = $this->repository->getByConversation($conversationId);

     return [
    'message' => 'Messages retrieved successfully',
    'status'  => ApiResponseConstants::SUCCESS, // 👈 INT
    'data'    => $messages
];
    }
}
