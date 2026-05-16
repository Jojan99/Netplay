<?php

namespace App\UseCases\Inventory\Interfaces;

interface DeleteInventoryCategoryUseCaseInterface
{
    public function delete(int $id): array;
}
