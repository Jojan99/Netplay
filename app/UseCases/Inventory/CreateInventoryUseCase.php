<?php

namespace App\UseCases\Inventory;

use App\Constants\ApiResponseConstants;
use App\Repositories\Interfaces\InventoryItemRepositoryInterface;
use App\UseCases\Inventory\Interfaces\CreateInventoryUseCaseInterface;

class CreateInventoryUseCase implements CreateInventoryUseCaseInterface
{
    public function __construct(
        private InventoryItemRepositoryInterface $itemRepository
    ) {}

    public function create(array $data): array
    {
        try {
            $companyId = (int) getSessionCompanyId();
            $item = $this->itemRepository->create($companyId, $data);

            return [
                'message' => 'Ítem creado correctamente',
                'data'    => $item,
                'status'  => ApiResponseConstants::SUCCESS,
            ];
        } catch (\Throwable $e) {
            return [
                'message' => 'Error al crear ítem: ' . $e->getMessage(),
                'data'    => null,
                'status'  => ApiResponseConstants::ERROR,
            ];
        }
    }
}
