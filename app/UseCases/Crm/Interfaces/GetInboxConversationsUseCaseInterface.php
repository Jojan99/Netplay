<?php

namespace App\UseCases\Crm\Interfaces;

interface GetInboxConversationsUseCaseInterface
{
    public function execute(array $filters = []): array;
}
