<?php

namespace App\UseCases\Inventory\Interfaces;

interface GetInventoriesUseCaseInterface
{
    public function getAll(array $filters = []): array;
    public function getById(int $id): array;
    public function getLowStock(): array;
    public function getLocations(): array;
}
