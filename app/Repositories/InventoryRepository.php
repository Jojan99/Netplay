<?php

namespace App\Repositories;

use App\Models\Inventory;
use App\Models\InventoryCategory;
use App\Models\InventoryMovement;
use App\Repositories\Interfaces\InventoryRepositoryInterface;

class InventoryRepository implements InventoryRepositoryInterface
{
    public function getCategories(): mixed
    {
        return InventoryCategory::where('company_id', getSessionCompanyId())->get();
    }

    public function createCategory(array $data): mixed
    {
        return InventoryCategory::create([
            'company_id'  => getSessionCompanyId(),
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
        ]);
    }

    public function getAll(): mixed
    {
        return Inventory::with('category')
            ->where('company_id', getSessionCompanyId())
            ->get();
    }

    public function getById(int $id): mixed
    {
        return Inventory::with('category', 'movements')
            ->where('company_id', getSessionCompanyId())
            ->findOrFail($id);
    }

    public function create(array $data): mixed
    {
        return Inventory::create([
            'company_id'  => getSessionCompanyId(),
            'category_id' => $data['category_id'] ?? null,
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
            'quantity'    => $data['quantity'] ?? 0,
            'unit_price'  => $data['unit_price'] ?? 0,
            'unit'        => $data['unit'] ?? 'unidades',
        ]);
    }

    public function update(int $id, array $data): mixed
    {
        $item = Inventory::where('company_id', getSessionCompanyId())->findOrFail($id);
        $item->update($data);
        return $item->fresh('category');
    }

    public function delete(int $id): mixed
    {
        $item = Inventory::where('company_id', getSessionCompanyId())->findOrFail($id);
        $item->delete();
        return true;
    }

    public function createMovement(array $data): mixed
    {
        $companyId = getSessionCompanyId();

        $item = Inventory::where('company_id', $companyId)->findOrFail($data['inventory_id']);

        if ($data['type'] === 'entrada') {
            $item->increment('quantity', $data['quantity']);
        } elseif ($data['type'] === 'salida') {
            $item->decrement('quantity', $data['quantity']);
        } else {
            // ajuste: quantity = valor absoluto nuevo
            $item->update(['quantity' => $data['quantity']]);
        }

        return InventoryMovement::create([
            'company_id'   => $companyId,
            'inventory_id' => $data['inventory_id'],
            'type'         => $data['type'],
            'quantity'     => $data['quantity'],
            'unit_price'   => $data['unit_price'] ?? 0,
            'description'  => $data['description'] ?? null,
            'reference'    => $data['reference'] ?? null,
            'user_id'      => getSessionUserId(),
        ]);
    }

    public function getMovements(int $inventoryId): mixed
    {
        return InventoryMovement::with('inventory')
            ->where('company_id', getSessionCompanyId())
            ->where('inventory_id', $inventoryId)
            ->orderByDesc('created_at')
            ->get();
    }

    public function getAllMovements(): mixed
    {
        return InventoryMovement::with('inventory')
            ->where('company_id', getSessionCompanyId())
            ->orderByDesc('created_at')
            ->get();
    }
}
