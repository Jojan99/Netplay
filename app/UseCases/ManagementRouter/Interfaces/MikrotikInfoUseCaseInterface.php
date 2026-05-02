<?php

namespace App\UseCases\ManagementRouter\Interfaces;

interface MikrotikInfoUseCaseInterface
{
    public function listRouters(): array;
    public function createRouter(array $data): array;
    public function updateRouter(int $id, array $data): array;
    public function deleteRouter(int $id): array;

    public function getRouterInfo(?int $routerId = null): array;
    public function getQueues(?int $routerId = null): array;
    public function createQueue(array $data, ?int $routerId = null): array;
    public function updateQueue(string $id, array $data, ?int $routerId = null): array;
    public function deleteQueue(string $id, ?int $routerId = null): array;
    public function suspendBulk(array $userIds, ?int $routerId = null): array;
    public function getConnectedClients(?int $routerId = null): array;
    public function getRouterConfig(?int $routerId = null): array;
    public function saveRouterConfig(array $data): array;
}
