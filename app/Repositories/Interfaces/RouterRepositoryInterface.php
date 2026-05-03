<?php

namespace App\Repositories\Interfaces;
interface RouterRepositoryInterface
{
    public function getCredentialRouter($token): mixed;
    public function getTokenByCompany(int $companyId): mixed;
    public function getRouterByCompany(int $companyId): mixed;
    public function getRoutersByCompany(int $companyId): mixed;
    public function getRouterById(int $id, int $companyId): mixed;
    public function saveRouterConfig(int $companyId, array $data): mixed;
    public function createRouter(int $companyId, array $data): mixed;
    public function updateRouter(int $id, int $companyId, array $data): mixed;
    public function deleteRouter(int $id, int $companyId): bool;
}