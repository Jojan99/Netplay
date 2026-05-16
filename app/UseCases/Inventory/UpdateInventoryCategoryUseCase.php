<?php

namespace App\UseCases\Inventory;

use App\Constants\ApiResponseConstants;
use App\Repositories\Interfaces\InventoryCategoryRepositoryInterface;
use App\UseCases\Inventory\Interfaces\CreateInventoryCategoryUseCaseInterface;

class UpdateInventoryCategoryUseCase implements CreateInventoryCategoryUseCaseInterface
{
    public function __construct(
        private InventoryCategoryRepositoryInterface $categoryRepository
    ) {}

    public function create(array $data): array
    {
        // Interface reuse: array contains ['id' => ..., ...fields]
        try {
            $companyId = (int) getSessionCompanyId();
            $id = (int) ($data['id'] ?? 0);
            $category = $this->categoryRepository->update($companyId, $id, $data);

            if (!$category) {
                return [
                    'message' => 'Categoría no encontrada',
                    'data'    => null,
                    'status'  => ApiResponseConstants::ERROR,
                ];
            }

            return [
                'message' => 'Categoría actualizada correctamente',
                'data'    => $category,
                'status'  => ApiResponseConstants::SUCCESS,
            ];
        } catch (\Throwable $e) {
            return [
                'message' => 'Error al actualizar categoría: ' . $e->getMessage(),
                'data'    => null,
                'status'  => ApiResponseConstants::ERROR,
            ];
        }
    }
}
