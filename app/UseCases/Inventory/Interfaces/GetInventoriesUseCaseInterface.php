<?php

namespace App\UseCases\Inventory\Interfaces;

interface GetInventoriesUseCaseInterface
{
    public function getAll(): mixed;
    public function getById(int $id): mixed;
}
