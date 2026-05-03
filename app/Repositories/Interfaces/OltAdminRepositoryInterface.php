<?php

namespace App\Repositories\Interfaces;

interface OltAdminRepositoryInterface
{
    public function getAllByCompany(): array;
    public function findById(int $id): ?array;
    public function create(array $data): array;
    public function update(int $id, array $data): bool;
    public function delete(int $id): bool;
}
