<?php

namespace App\UseCases\Inventory;

use App\Constants\ApiResponseConstants;
use App\Repositories\Interfaces\InventoryRepositoryInterface;
use App\UseCases\Inventory\Interfaces\CreateInventoryUseCaseInterface;
use Illuminate\Http\Request;

class CreateInventoryUseCase implements CreateInventoryUseCaseInterface
{
    public function __construct(
        private InventoryRepositoryInterface $inventoryRepository
    ) {}

    public function create(Request $request): mixed
    {
        try {
            $data = $this->inventoryRepository->create($request->all());
            return ['message' => 'Ítem creado correctamente', 'data' => $data, 'status' => ApiResponseConstants::SUCCESS];
        } catch (\Throwable $e) {
            return ['message' => $e->getMessage(), 'data' => null, 'status' => ApiResponseConstants::ERROR];
        }
    }
}
