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

            // La cantidad no se cambia editando el ítem: la pantalla manda la que tenía cuando se
            // cargó la lista, y con eso se devolvía la existencia a un valor viejo sin que nadie
            // lo pidiera. Para corregirla está el ajuste por conteo (un movimiento, con rastro).

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
