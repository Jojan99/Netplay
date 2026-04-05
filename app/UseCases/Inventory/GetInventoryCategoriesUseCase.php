<?php

namespace App\UseCases\Inventory;

use App\Constants\ApiResponseConstants;
use App\Repositories\Interfaces\InventoryRepositoryInterface;
use App\UseCases\Inventory\Interfaces\GetInventoryCategoriesUseCaseInterface;

class GetInventoryCategoriesUseCase implements GetInventoryCategoriesUseCaseInterface
{
    public function __construct(
        private InventoryRepositoryInterface $inventoryRepository
    ) {}

    public function getAll(): mixed
    {
        try {
            $data = $this->inventoryRepository->getCategories();
            return ['message' => 'OK', 'data' => $data, 'status' => ApiResponseConstants::SUCCESS];
        } catch (\Throwable $e) {
            return ['message' => $e->getMessage(), 'data' => null, 'status' => ApiResponseConstants::ERROR];
        }
    }
}
