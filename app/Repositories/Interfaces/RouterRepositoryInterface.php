<?php

namespace App\Repositories\Interfaces;
interface RouterRepositoryInterface

{
    public function getCredentialRouter($token): mixed;
    public function getTokenByCompany(int $companyId): mixed;
    public function getRouterByCompany(int $companyId): mixed;
    public function saveRouterConfig(int $companyId, array $data): mixed;
}