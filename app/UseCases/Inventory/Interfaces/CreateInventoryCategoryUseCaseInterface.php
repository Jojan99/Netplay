<?php

namespace App\UseCases\Inventory\Interfaces;

interface CreateInventoryCategoryUseCaseInterface
{
    public function create(array $data): array;
}
