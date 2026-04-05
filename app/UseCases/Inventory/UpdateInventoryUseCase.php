<?php

namespace App\UseCases\Inventory;

use App\Constants\ApiResponseConstants;
use App\Repositories\Interfaces\InventoryRepositoryInterface;
use App\UseCases\Inventory\Interfaces\UpdateInventoryUseCaseInterface;
use Illuminate\Http\Request;

class UpdateInventoryUseCase implements UpdateInventoryUseCaseInterface
{
    public function __construct(
        private InventoryRepositoryInterface $inventoryRepository
    ) {}

    public function update(int $id, Request $request): mixed
    {
        try {
            $data = $this->inventoryRepository->update($id, $request->all());
            return ['message' => 'Ítem actualizado correctamente', 'data' => $data, 'status' => ApiResponseConstants::SUCCESS];
        } catch (\Throwable $e) {
            return ['message' => $e->getMessage(), 'data' => null, 'status' => ApiResponseConstants::ERROR];
        }
    }
}
