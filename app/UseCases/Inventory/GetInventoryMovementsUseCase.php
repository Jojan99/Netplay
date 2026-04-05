<?php

namespace App\UseCases\Inventory;

use App\Constants\ApiResponseConstants;
use App\Repositories\Interfaces\InventoryRepositoryInterface;
use App\UseCases\Inventory\Interfaces\GetInventoryMovementsUseCaseInterface;

class GetInventoryMovementsUseCase implements GetInventoryMovementsUseCaseInterface
{
    public function __construct(
        private InventoryRepositoryInterface $inventoryRepository
    ) {}

    public function getByItem(int $inventoryId): mixed
    {
        try {
            $data = $this->inventoryRepository->getMovements($inventoryId);
            return ['message' => 'OK', 'data' => $data, 'status' => ApiResponseConstants::SUCCESS];
        } catch (\Throwable $e) {
            return ['message' => $e->getMessage(), 'data' => null, 'status' => ApiResponseConstants::ERROR];
        }
    }

    public function getAll(): mixed
    {
        try {
            $data = $this->inventoryRepository->getAllMovements();
            return ['message' => 'OK', 'data' => $data, 'status' => ApiResponseConstants::SUCCESS];
        } catch (\Throwable $e) {
            return ['message' => $e->getMessage(), 'data' => null, 'status' => ApiResponseConstants::ERROR];
        }
    }
}
