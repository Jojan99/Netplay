<?php

namespace App\Repositories\Interfaces;

use Illuminate\Pagination\LengthAwarePaginator;
use App\Models\Inventory;

interface InventoryItemRepositoryInterface
{
    public function getAll(int $companyId, array $filters = [], int $perPage = 15): LengthAwarePaginator;
    public function findById(int $companyId, int $id): ?Inventory;
    public function findByIdWithMovements(int $companyId, int $id): ?Inventory;
    public function create(int $companyId, array $data): Inventory;
    public function update(int $companyId, int $id, array $data): ?Inventory;
    public function delete(int $companyId, int $id): bool;
    public function getLowStock(int $companyId, int $perPage = 15): LengthAwarePaginator;
    public function getLocations(int $companyId): array;
}
