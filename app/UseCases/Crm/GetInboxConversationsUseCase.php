<?php

namespace App\UseCases\Crm;

use App\Repositories\Interfaces\ConversationRepositoryInterface;
use App\UseCases\Crm\Interfaces\GetInboxConversationsUseCaseInterface;

class GetInboxConversationsUseCase implements GetInboxConversationsUseCaseInterface
{
    public function __construct(
        private ConversationRepositoryInterface $repository
    ) {}

    public function execute(array $filters = []): array
    {
        return $this->repository->getInbox($filters);
    }
}
