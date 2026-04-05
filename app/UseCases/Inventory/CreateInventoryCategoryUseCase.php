<?php

namespace App\UseCases\Inventory;

use App\Constants\ApiResponseConstants;
use App\Repositories\Interfaces\InventoryRepositoryInterface;
use App\UseCases\Inventory\Interfaces\CreateInventoryCategoryUseCaseInterface;
use Illuminate\Http\Request;

class CreateInventoryCategoryUseCase implements CreateInventoryCategoryUseCaseInterface
{
    public function __construct(
        private InventoryRepositoryInterface $inventoryRepository
    ) {}

    public function create(Request $request): mixed
    {
        try {
            $data = $this->inventoryRepository->createCategory($request->all());
            return ['message' => 'Categoría creada correctamente', 'data' => $data, 'status' => ApiResponseConstants::SUCCESS];
        } catch (\Throwable $e) {
            return ['message' => $e->getMessage(), 'data' => null, 'status' => ApiResponseConstants::ERROR];
        }
    }
}
