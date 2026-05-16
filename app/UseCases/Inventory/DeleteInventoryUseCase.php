<?php

namespace App\UseCases\Inventory;

use App\Constants\ApiResponseConstants;
use App\Repositories\Interfaces\InventoryItemRepositoryInterface;
use App\UseCases\Inventory\Interfaces\DeleteInventoryUseCaseInterface;

class DeleteInventoryUseCase implements DeleteInventoryUseCaseInterface
{
    public function __construct(
        private InventoryItemRepositoryInterface $itemRepository
    ) {}

    public function delete(int $id): array
    {
        try {
            $companyId = (int) getSessionCompanyId();
            $deleted = $this->itemRepository->delete($companyId, $id);

            if (!$deleted) {
                return [
                    'message' => 'Ítem no encontrado',
                    'data'    => null,
                    'status'  => ApiResponseConstants::ERROR,
                ];
            }

            return [
                'message' => 'Ítem eliminado correctamente',
                'data'    => null,
                'status'  => ApiResponseConstants::SUCCESS,
            ];
        } catch (\Throwable $e) {
            return [
                'message' => 'Error al eliminar ítem: ' . $e->getMessage(),
                'data'    => null,
                'status'  => ApiResponseConstants::ERROR,
            ];
        }
    }
}
