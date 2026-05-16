<?php

namespace App\Repositories;

use App\Models\Inventory;
use App\Repositories\Interfaces\InventoryItemRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class InventoryItemRepository implements InventoryItemRepositoryInterface
{
    public function getAll(int $companyId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Inventory::byCompany($companyId)
            ->with('category')
            ->withSum('movements as total_in', 'quantity', fn ($q) => $q->where('type', 'entrada'))
            ->withSum('movements as total_out', 'quantity', fn ($q) => $q->where('type', 'salida'));

        if (!empty($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        if (!empty($filters['search'])) {
            $query->search($filters['search']);
        }

        if (!empty($filters['location'])) {
            $query->where('location', $filters['location']);
        }

        if (isset($filters['low_stock']) && $filters['low_stock']) {
            $query->lowStock();
        }

        if (!empty($filters['sort_by'])) {
            $direction = $filters['sort_direction'] ?? 'asc';
            $query->orderBy($filters['sort_by'], $direction);
        } else {
            $query->orderBy('name');
        }

        return $query->paginate($perPage);
    }

    public function findById(int $companyId, int $id): ?Inventory
    {
        return Inventory::byCompany($companyId)->find($id);
    }

    public function findByIdWithMovements(int $companyId, int $id): ?Inventory
    {
        return Inventory::byCompany($companyId)
            ->with(['category', 'movements.user'])
            ->find($id);
    }

    public function create(int $companyId, array $data): Inventory
    {
        return Inventory::create([
            'company_id'   => $companyId,
            'category_id'  => $data['category_id'] ?? null,
            'name'         => $data['name'],
            'description'  => $data['description'] ?? null,
            'sku'          => $data['sku'] ?? null,
            'code'         => $data['code'] ?? null,
            'quantity'     => $data['quantity'] ?? 0,
            'stock_min'    => $data['stock_min'] ?? 0,
            'stock_max'    => $data['stock_max'] ?? null,
            'unit_price'   => $data['unit_price'] ?? 0,
            'average_cost' => $data['average_cost'] ?? 0,
            'unit'         => $data['unit'] ?? 'unidades',
            'location'     => $data['location'] ?? null,
        ]);
    }

    public function update(int $companyId, int $id, array $data): ?Inventory
    {
        $item = $this->findById($companyId, $id);
        if (!$item) {
            return null;
        }

        $item->update([
            'category_id'  => $data['category_id'] ?? $item->category_id,
            'name'         => $data['name'] ?? $item->name,
            'description'  => $data['description'] ?? $item->description,
            'sku'          => $data['sku'] ?? $item->sku,
            'code'         => $data['code'] ?? $item->code,
            'stock_min'    => $data['stock_min'] ?? $item->stock_min,
            'stock_max'    => $data['stock_max'] ?? $item->stock_max,
            'unit_price'   => $data['unit_price'] ?? $item->unit_price,
            'unit'         => $data['unit'] ?? $item->unit,
            'location'     => $data['location'] ?? $item->location,
        ]);

        return $item->fresh('category');
    }

    public function delete(int $companyId, int $id): bool
    {
        $item = $this->findById($companyId, $id);
        if (!$item) {
            return false;
        }
        $item->delete();
        return true;
    }

    public function getLowStock(int $companyId, int $perPage = 15): LengthAwarePaginator
    {
        return Inventory::byCompany($companyId)
            ->with('category')
            ->lowStock()
            ->orderBy('quantity', 'asc')
            ->paginate($perPage);
    }

    public function getLocations(int $companyId): array
    {
        return Inventory::byCompany($companyId)
            ->whereNotNull('location')
            ->distinct()
            ->pluck('location')
            ->toArray();
    }
}
