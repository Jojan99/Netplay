<?php

namespace App\UseCases\Inventory;

use App\Constants\ApiResponseConstants;
use App\Repositories\Interfaces\InventoryRepositoryInterface;
use App\UseCases\Inventory\Interfaces\CreateInventoryMovementUseCaseInterface;
use Illuminate\Http\Request;

class CreateInventoryMovementUseCase implements CreateInventoryMovementUseCaseInterface
{
    public function __construct(
        private InventoryRepositoryInterface $inventoryRepository
    ) {}

    public function create(Request $request): mixed
    {
        try {
            $data = $this->inventoryRepository->createMovement($request->all());
            return ['message' => 'Movimiento registrado correctamente', 'data' => $data, 'status' => ApiResponseConstants::SUCCESS];
        } catch (\Throwable $e) {
            return ['message' => $e->getMessage(), 'data' => null, 'status' => ApiResponseConstants::ERROR];
        }
    }
}
