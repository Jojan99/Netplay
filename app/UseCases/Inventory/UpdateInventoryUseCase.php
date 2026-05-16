<?php

namespace App\UseCases\Inventory;

use App\Constants\ApiResponseConstants;
use App\Models\InventoryMovement;
use App\Repositories\Interfaces\InventoryItemRepositoryInterface;
use App\UseCases\Inventory\Interfaces\CreateInventoryMovementUseCaseInterface;
use App\UseCases\Inventory\Interfaces\UpdateInventoryUseCaseInterface;

class UpdateInventoryUseCase implements UpdateInventoryUseCaseInterface
{
    public function __construct(
        private InventoryItemRepositoryInterface $itemRepository,
        private CreateInventoryMovementUseCaseInterface $movementUseCase,
    ) {}

    public function update(int $id, array $data): array
    {
        try {
            $companyId = (int) getSessionCompanyId();
            $newQuantity = $data['quantity'] ?? null;
            unset($data['quantity']);

            $item = $this->itemRepository->findById($companyId, $id);

            if (!$item) {
                return [
                    'message' => 'Ítem no encontrado',
                    'data'    => null,
                    'status'  => ApiResponseConstants::ERROR,
                ];
            }

            // Si se envió cantidad diferente, registrar ajuste para trazabilidad
            if ($newQuantity !== null && (float) $newQuantity !== (float) $item->quantity) {
                $movementResult = $this->movementUseCase->create([
                    'inventory_id' => $id,
                    'type'         => InventoryMovement::TYPE_AJUSTE,
                    'quantity'     => (float) $newQuantity,
                    'unit_price'   => 0,
                    'description'  => 'Ajuste por edición manual de cantidad',
                    'reference'    => 'ajuste-edición',
                ]);

                if ($movementResult['status'] !== ApiResponseConstants::SUCCESS) {
                    return $movementResult;
                }
            }

            $item = $this->itemRepository->update($companyId, $id, $data);

            return [
                'message' => 'Ítem actualizado correctamente',
                'data'    => $item->fresh('category'),
                'status'  => ApiResponseConstants::SUCCESS,
            ];
        } catch (\Throwable $e) {
            return [
                'message' => 'Error al actualizar ítem: ' . $e->getMessage(),
                'data'    => null,
                'status'  => ApiResponseConstants::ERROR,
            ];
        }
    }
}
