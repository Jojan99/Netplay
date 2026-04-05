<?php

namespace App\Repositories\Interfaces;

interface InventoryRepositoryInterface
{
    public function getCategories(): mixed;
    public function createCategory(array $data): mixed;
    public function getAll(): mixed;
    public function getById(int $id): mixed;
    public function create(array $data): mixed;
    public function update(int $id, array $data): mixed;
    public function delete(int $id): mixed;
    public function createMovement(array $data): mixed;
    public function getMovements(int $inventoryId): mixed;
    public function getAllMovements(): mixed;
}
