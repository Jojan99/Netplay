<?php

namespace App\Repositories\Interfaces;

use Illuminate\Pagination\LengthAwarePaginator;
use App\Models\InventoryMovement;

interface InventoryMovementRepositoryInterface
{
    public function getAll(int $companyId, array $filters = [], int $perPage = 15): LengthAwarePaginator;
    public function getByItem(int $companyId, int $inventoryId, int $perPage = 15): LengthAwarePaginator;
    public function findById(int $companyId, int $id): ?InventoryMovement;
    public function create(int $companyId, array $data): InventoryMovement;
    public function getLastMovement(int $companyId, int $inventoryId): ?InventoryMovement;
    public function getStockValuation(int $companyId): array;
}
