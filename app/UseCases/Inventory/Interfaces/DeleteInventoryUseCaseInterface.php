<?php

namespace App\UseCases\Inventory\Interfaces;

interface DeleteInventoryUseCaseInterface
{
    public function delete(int $id): mixed;
}
