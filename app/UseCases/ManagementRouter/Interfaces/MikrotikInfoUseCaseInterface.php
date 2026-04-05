<?php

namespace App\UseCases\ManagementRouter\Interfaces;

interface MikrotikInfoUseCaseInterface
{
    public function getRouterInfo(): array;
    public function getQueues(): array;
    public function createQueue(array $data): array;
    public function updateQueue(string $id, array $data): array;
    public function deleteQueue(string $id): array;
    public function suspendBulk(array $userIds): array;
    public function getConnectedClients(): array;
    public function getRouterConfig(): array;
    public function saveRouterConfig(array $data): array;
}
