<?php

namespace App\UseCases\Inventory;

use App\Constants\ApiResponseConstants;
use App\Repositories\Interfaces\InventoryRepositoryInterface;
use App\UseCases\Inventory\Interfaces\GetInventoriesUseCaseInterface;

class GetInventoriesUseCase implements GetInventoriesUseCaseInterface
{
    public function __construct(
        private InventoryRepositoryInterface $inventoryRepository
    ) {}

    public function getAll(): mixed
    {
        try {
            $data = $this->inventoryRepository->getAll();
            return ['message' => 'OK', 'data' => $data, 'status' => ApiResponseConstants::SUCCESS];
        } catch (\Throwable $e) {
            return ['message' => $e->getMessage(), 'data' => null, 'status' => ApiResponseConstants::ERROR];
        }
    }

    public function getById(int $id): mixed
    {
        try {
            $data = $this->inventoryRepository->getById($id);
            return ['message' => 'OK', 'data' => $data, 'status' => ApiResponseConstants::SUCCESS];
        } catch (\Throwable $e) {
            return ['message' => $e->getMessage(), 'data' => null, 'status' => ApiResponseConstants::ERROR];
        }
    }
}
