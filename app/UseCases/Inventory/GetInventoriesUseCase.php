<?php

namespace App\UseCases\Inventory;

use App\Constants\ApiResponseConstants;
use App\Repositories\Interfaces\InventoryItemRepositoryInterface;
use App\UseCases\Inventory\Interfaces\GetInventoriesUseCaseInterface;

class GetInventoriesUseCase implements GetInventoriesUseCaseInterface
{
    public function __construct(
        private InventoryItemRepositoryInterface $itemRepository
    ) {}

    public function getAll(array $filters = []): array
    {
        try {
            $companyId = (int) getSessionCompanyId();
            $perPage = $filters['per_page'] ?? 15;
            $data = $this->itemRepository->getAll($companyId, $filters, (int) $perPage);

            return [
                'message' => 'Ítems obtenidos correctamente',
                'data'    => $data,
                'status'  => ApiResponseConstants::SUCCESS,
            ];
        } catch (\Throwable $e) {
            return [
                'message' => 'Error al obtener ítems: ' . $e->getMessage(),
                'data'    => null,
                'status'  => ApiResponseConstants::ERROR,
            ];
        }
    }

    public function getById(int $id): array
    {
        try {
            $companyId = (int) getSessionCompanyId();
            $item = $this->itemRepository->findByIdWithMovements($companyId, $id);

            if (!$item) {
                return [
                    'message' => 'Ítem no encontrado',
                    'data'    => null,
                    'status'  => ApiResponseConstants::ERROR,
                ];
            }

            return [
                'message' => 'Ítem obtenido correctamente',
                'data'    => $item,
                'status'  => ApiResponseConstants::SUCCESS,
            ];
        } catch (\Throwable $e) {
            return [
                'message' => 'Error al obtener ítem: ' . $e->getMessage(),
                'data'    => null,
                'status'  => ApiResponseConstants::ERROR,
            ];
        }
    }

    public function getLowStock(): array
    {
        try {
            $companyId = (int) getSessionCompanyId();
            $data = $this->itemRepository->getLowStock($companyId);

            return [
                'message' => 'Ítems con stock bajo obtenidos',
                'data'    => $data,
                'status'  => ApiResponseConstants::SUCCESS,
            ];
        } catch (\Throwable $e) {
            return [
                'message' => 'Error al obtener stock bajo: ' . $e->getMessage(),
                'data'    => null,
                'status'  => ApiResponseConstants::ERROR,
            ];
        }
    }

    public function getLocations(): array
    {
        try {
            $companyId = (int) getSessionCompanyId();
            $data = $this->itemRepository->getLocations($companyId);

            return [
                'message' => 'Ubicaciones obtenidas correctamente',
                'data'    => $data,
                'status'  => ApiResponseConstants::SUCCESS,
            ];
        } catch (\Throwable $e) {
            return [
                'message' => 'Error al obtener ubicaciones: ' . $e->getMessage(),
                'data'    => null,
                'status'  => ApiResponseConstants::ERROR,
            ];
        }
    }
}
