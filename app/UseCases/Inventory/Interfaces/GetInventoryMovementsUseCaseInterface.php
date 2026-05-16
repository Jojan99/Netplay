<?php

namespace App\UseCases\Inventory\Interfaces;

interface GetInventoryMovementsUseCaseInterface
{
    public function getByItem(int $inventoryId): array;
    public function getAll(array $filters = []): array;
}
