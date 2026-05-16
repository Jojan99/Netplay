<?php

namespace App\UseCases\Inventory;

use App\Constants\ApiResponseConstants;
use App\Repositories\Interfaces\InventoryCategoryRepositoryInterface;

class DeleteInventoryCategoryUseCase
{
    public function __construct(
        private InventoryCategoryRepositoryInterface $categoryRepository
    ) {}

    public function delete(int $id): array
    {
        try {
            $companyId = (int) getSessionCompanyId();
            $deleted = $this->categoryRepository->delete($companyId, $id);

            if (!$deleted) {
                return [
                    'message' => 'Categoría no encontrada',
                    'data'    => null,
                    'status'  => ApiResponseConstants::ERROR,
                ];
            }

            return [
                'message' => 'Categoría eliminada correctamente',
                'data'    => null,
                'status'  => ApiResponseConstants::SUCCESS,
            ];
        } catch (\Throwable $e) {
            return [
                'message' => 'Error al eliminar categoría: ' . $e->getMessage(),
                'data'    => null,
                'status'  => ApiResponseConstants::ERROR,
            ];
        }
    }
}
