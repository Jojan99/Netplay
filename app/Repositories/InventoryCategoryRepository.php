<?php

namespace App\Repositories;

use App\Models\InventoryCategory;
use App\Repositories\Interfaces\InventoryCategoryRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class InventoryCategoryRepository implements InventoryCategoryRepositoryInterface
{
    public function getAll(int $companyId): Collection
    {
        return InventoryCategory::byCompany($companyId)
            ->withCount('inventories')
            ->orderBy('name')
            ->get();
    }

    public function findById(int $companyId, int $id): ?InventoryCategory
    {
        return InventoryCategory::byCompany($companyId)->find($id);
    }

    public function create(int $companyId, array $data): InventoryCategory
    {
        return InventoryCategory::create([
            'company_id'  => $companyId,
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
            // ONT y TV box por serial; conectores y RJ45 por cantidad.
            'usa_serial'  => array_key_exists('usa_serial', $data) ? (bool) $data['usa_serial'] : true,
        ]);
    }

    public function update(int $companyId, int $id, array $data): ?InventoryCategory
    {
        $category = $this->findById($companyId, $id);
        if (!$category) {
            return null;
        }
        $category->update([
            'name'        => $data['name'] ?? $category->name,
            'description' => $data['description'] ?? $category->description,
            'usa_serial'  => array_key_exists('usa_serial', $data) ? (bool) $data['usa_serial'] : $category->usa_serial,
        ]);
        return $category->fresh();
    }

    public function delete(int $companyId, int $id): bool
    {
        $category = $this->findById($companyId, $id);
        if (!$category) {
            return false;
        }
        $category->delete();
        return true;
    }
}
