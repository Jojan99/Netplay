<?php

namespace App\Repositories;

use App\Models\InventoryMovement;
use App\Repositories\Interfaces\InventoryMovementRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class InventoryMovementRepository implements InventoryMovementRepositoryInterface
{
    public function getAll(int $companyId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = InventoryMovement::byCompany($companyId)
            ->with(['inventory.category', 'user'])
            ->orderByDesc('created_at');

        if (!empty($filters['inventory_id'])) {
            $query->where('inventory_id', $filters['inventory_id']);
        }

        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (!empty($filters['reference'])) {
            $query->where('reference', 'like', "%{$filters['reference']}%");
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        return $query->paginate($perPage);
    }

    public function getByItem(int $companyId, int $inventoryId, int $perPage = 15): LengthAwarePaginator
    {
        return InventoryMovement::byCompany($companyId)
            ->byItem($inventoryId)
            ->with('user')
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function findById(int $companyId, int $id): ?InventoryMovement
    {
        return InventoryMovement::byCompany($companyId)->find($id);
    }

    public function create(int $companyId, array $data): InventoryMovement
    {
        return InventoryMovement::create([
            'company_id'    => $companyId,
            'inventory_id'  => $data['inventory_id'],
            'type'          => $data['type'],
            'quantity'      => $data['quantity'],
            'unit_price'    => $data['unit_price'] ?? 0,
            'balance_after' => $data['balance_after'] ?? 0,
            'cost_before'   => $data['cost_before'] ?? 0,
            'cost_after'    => $data['cost_after'] ?? 0,
            'description'   => $data['description'] ?? null,
            'reference'     => $data['reference'] ?? null,
            'batch_number'  => $data['batch_number'] ?? null,
            'expiry_date'   => $data['expiry_date'] ?? null,
            'user_id'       => $data['user_id'] ?? null,
        ]);
    }

    public function getLastMovement(int $companyId, int $inventoryId): ?InventoryMovement
    {
        return InventoryMovement::byCompany($companyId)
            ->byItem($inventoryId)
            ->orderByDesc('created_at')
            ->first();
    }

    public function getStockValuation(int $companyId): array
    {
        $result = InventoryMovement::byCompany($companyId)
            ->selectRaw("SUM(CASE WHEN type = 'entrada' THEN quantity * unit_price ELSE 0 END) as total_valuation")
            ->selectRaw("SUM(CASE WHEN type = 'salida' THEN quantity * unit_price ELSE 0 END) as total_out_valuation")
            ->first();

        return [
            'total_valuation' => (float) ($result->total_valuation ?? 0),
            'total_out_valuation' => (float) ($result->total_out_valuation ?? 0),
            'net_valuation' => (float) (($result->total_valuation ?? 0) - ($result->total_out_valuation ?? 0)),
        ];
    }
}
