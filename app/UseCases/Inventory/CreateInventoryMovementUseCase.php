<?php

namespace App\UseCases\Inventory;

use App\Constants\ApiResponseConstants;
use App\Models\InventoryMovement;
use App\Repositories\Interfaces\InventoryItemRepositoryInterface;
use App\Repositories\Interfaces\InventoryMovementRepositoryInterface;
use App\UseCases\Inventory\Interfaces\CreateInventoryMovementUseCaseInterface;
use Illuminate\Support\Facades\DB;

class CreateInventoryMovementUseCase implements CreateInventoryMovementUseCaseInterface
{
    public function __construct(
        private InventoryItemRepositoryInterface $itemRepository,
        private InventoryMovementRepositoryInterface $movementRepository,
    ) {}

    /**
     * Entrada, salida o ajuste desde el panel. La cuenta y el candado los pone Bodega: aquí
     * sólo se traduce la petición y se arma la respuesta que la pantalla ya esperaba.
     */
    public function create(array $data): array
    {
        try {
            $companyId   = (int) getSessionCompanyId();
            $inventoryId = (int) $data['inventory_id'];
            $cantidad    = (float) $data['quantity'];
            $usuarioId   = getSessionUserId() ? (int) getSessionUserId() : null;
            $seriales    = array_values(array_filter(array_merge((array) ($data['seriales'] ?? []), [$data['serial_number'] ?? null])));
            $bodega      = new \App\Services\Inventario\Bodega($companyId);

            $r = match ($data['type']) {
                InventoryMovement::TYPE_ENTRADA => $bodega->entrada($inventoryId, $cantidad, (float) ($data['unit_price'] ?? 0), $seriales, $data['reference'] ?? null, $data['description'] ?? null, $usuarioId),
                InventoryMovement::TYPE_SALIDA  => $bodega->salida($inventoryId, $cantidad, $data['reference'] ?? null, $data['description'] ?? null, $usuarioId, $seriales),
                InventoryMovement::TYPE_AJUSTE  => $bodega->ajuste($inventoryId, $cantidad, $data['description'] ?? null, $usuarioId),
                default => throw new \DomainException('Tipo de movimiento no válido.'),
            };

            $movimiento = InventoryMovement::find($r['movimiento_id']);

            return [
                'message' => 'Movimiento registrado correctamente',
                'data'    => ['movement' => $movimiento, 'new_quantity' => $r['en_bodega'], 'balance_after' => $r['en_bodega']],
                'status'  => ApiResponseConstants::SUCCESS,
            ];
        } catch (\DomainException $e) {
            return ['message' => $e->getMessage(), 'data' => null, 'status' => ApiResponseConstants::ERROR];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[Inventario] Falló el movimiento', ['error' => $e->getMessage()]);

            return ['message' => 'No se pudo registrar el movimiento.', 'data' => null, 'status' => ApiResponseConstants::ERROR];
        }
    }
}
