<?php

namespace App\UseCases\Inventory\Interfaces;

interface UpdateInventoryUseCaseInterface
{
    public function update(int $id, array $data): array;
}
