<?php

namespace App\Repositories\Interfaces;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use App\Models\InventoryCategory;

interface InventoryCategoryRepositoryInterface
{
    public function getAll(int $companyId): Collection;
    public function findById(int $companyId, int $id): ?InventoryCategory;
    public function create(int $companyId, array $data): InventoryCategory;
    public function update(int $companyId, int $id, array $data): ?InventoryCategory;
    public function delete(int $companyId, int $id): bool;
}
