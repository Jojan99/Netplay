<?php

namespace App\UseCases\Inventory\Interfaces;

interface GetInventoryCategoriesUseCaseInterface
{
    public function getAll(): array;
}
