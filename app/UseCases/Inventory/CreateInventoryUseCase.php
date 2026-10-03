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
            $inicial = (float) ($data['quantity'] ?? 0);
            $item = $this->itemRepository->create($companyId, ['quantity' => 0] + $data);

            // La existencia inicial entra como un movimiento más: queda el rastro y cuadra con
            // lo que después recalculan los comandos de mantenimiento.
            if ($inicial > 0 && empty($data['usa_serial'])) {
                (new \App\Services\Inventario\Bodega($companyId))->entrada((int) $item->id, $inicial, (float) ($data['unit_price'] ?? 0), [], 'existencia-inicial', 'Existencia inicial', getSessionUserId() ? (int) getSessionUserId() : null);
                $item->refresh();
            }

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
