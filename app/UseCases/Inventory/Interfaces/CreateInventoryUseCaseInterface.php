<?php

namespace App\UseCases\Inventory\Interfaces;

interface CreateInventoryUseCaseInterface
{
    public function create(array $data): array;
}
