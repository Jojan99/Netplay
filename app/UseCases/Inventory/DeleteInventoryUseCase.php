<?php

namespace App\UseCases\Inventory;

use App\Constants\ApiResponseConstants;
use App\Repositories\Interfaces\InventoryRepositoryInterface;
use App\UseCases\Inventory\Interfaces\DeleteInventoryUseCaseInterface;

class DeleteInventoryUseCase implements DeleteInventoryUseCaseInterface
{
    public function __construct(
        private InventoryRepositoryInterface $inventoryRepository
    ) {}

    public function delete(int $id): mixed
    {
        try {
            $this->inventoryRepository->delete($id);
            return ['message' => 'Ítem eliminado correctamente', 'data' => null, 'status' => ApiResponseConstants::SUCCESS];
        } catch (\Throwable $e) {
            return ['message' => $e->getMessage(), 'data' => null, 'status' => ApiResponseConstants::ERROR];
        }
    }
}
