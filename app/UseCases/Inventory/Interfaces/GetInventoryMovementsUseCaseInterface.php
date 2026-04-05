<?php

namespace App\UseCases\Inventory\Interfaces;

interface GetInventoryMovementsUseCaseInterface
{
    public function getByItem(int $inventoryId): mixed;
    public function getAll(): mixed;
}
