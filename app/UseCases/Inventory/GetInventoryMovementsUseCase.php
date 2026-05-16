<?php

namespace App\UseCases\Inventory;

use App\Constants\ApiResponseConstants;
use App\Repositories\Interfaces\InventoryMovementRepositoryInterface;
use App\UseCases\Inventory\Interfaces\GetInventoryMovementsUseCaseInterface;

class GetInventoryMovementsUseCase implements GetInventoryMovementsUseCaseInterface
{
    public function __construct(
        private InventoryMovementRepositoryInterface $movementRepository
    ) {}

    public function getByItem(int $inventoryId): array
    {
        try {
            $companyId = (int) getSessionCompanyId();
            $perPage = request()->query('per_page', 15);
            $data = $this->movementRepository->getByItem($companyId, $inventoryId, (int) $perPage);

            return [
                'message' => 'Movimientos obtenidos correctamente',
                'data'    => $data,
                'status'  => ApiResponseConstants::SUCCESS,
            ];
        } catch (\Throwable $e) {
            return [
                'message' => 'Error al obtener movimientos: ' . $e->getMessage(),
                'data'    => null,
                'status'  => ApiResponseConstants::ERROR,
            ];
        }
    }

    public function getAll(array $filters = []): array
    {
        try {
            $companyId = (int) getSessionCompanyId();
            $perPage = $filters['per_page'] ?? 15;
            $data = $this->movementRepository->getAll($companyId, $filters, (int) $perPage);

            return [
                'message' => 'Movimientos obtenidos correctamente',
                'data'    => $data,
                'status'  => ApiResponseConstants::SUCCESS,
            ];
        } catch (\Throwable $e) {
            return [
                'message' => 'Error al obtener movimientos: ' . $e->getMessage(),
                'data'    => null,
                'status'  => ApiResponseConstants::ERROR,
            ];
        }
    }
}
