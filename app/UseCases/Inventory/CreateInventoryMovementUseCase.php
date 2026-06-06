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

    public function create(array $data): array
    {
        DB::beginTransaction();

        try {
            $companyId = (int) getSessionCompanyId();
            $inventoryId = (int) $data['inventory_id'];
            $type = $data['type'];
            $quantity = (float) $data['quantity'];
            $unitPrice = (float) ($data['unit_price'] ?? 0);
            $userId = getSessionUserId() ? (int) getSessionUserId() : null;

            $item = $this->itemRepository->findById($companyId, $inventoryId);

            if (!$item) {
                DB::rollBack();
                return [
                    'message' => 'Ítem de inventario no encontrado',
                    'data'    => null,
                    'status'  => ApiResponseConstants::ERROR,
                ];
            }

            $currentQty = (float) $item->quantity;
            $currentAvgCost = (float) $item->average_cost;
            $newQty = $currentQty;
            $newAvgCost = $currentAvgCost;
            $balanceAfter = $currentQty;

            switch ($type) {
                case InventoryMovement::TYPE_ENTRADA:
                    $newQty = $currentQty + $quantity;

                    // Cálculo de costo promedio ponderado
                    if ($newQty > 0) {
                        $totalCurrentValue = $currentQty * $currentAvgCost;
                        $totalIncomingValue = $quantity * $unitPrice;
                        $newAvgCost = ($totalCurrentValue + $totalIncomingValue) / $newQty;
                    } else {
                        $newAvgCost = $unitPrice;
                    }

                    $item->quantity = $newQty;
                    $item->average_cost = round($newAvgCost, 2);
                    $item->save();
                    $balanceAfter = $newQty;
                    break;

                case InventoryMovement::TYPE_SALIDA:
                    if ($quantity > $currentQty) {
                        DB::rollBack();
                        return [
                            'message' => sprintf(
                                'Stock insuficiente. Disponible: %s, Solicitado: %s',
                                number_format($currentQty, 2),
                                number_format($quantity, 2)
                            ),
                            'data'    => [
                                'available' => $currentQty,
                                'requested' => $quantity,
                            ],
                            'status'  => ApiResponseConstants::ERROR,
                        ];
                    }

                    $newQty = $currentQty - $quantity;
                    $item->quantity = $newQty;
                    $item->save();
                    $balanceAfter = $newQty;
                    // El costo promedio no cambia en salidas
                    break;

                case InventoryMovement::TYPE_AJUSTE:
                    // Ajuste absoluto: el usuario envía el valor deseado de stock
                    $targetQty = $quantity;
                    $newQty = $targetQty;
                    $item->quantity = $newQty;
                    $item->save();
                    $balanceAfter = $newQty;
                    break;

                default:
                    DB::rollBack();
                    return [
                        'message' => 'Tipo de movimiento no válido',
                        'data'    => null,
                        'status'  => ApiResponseConstants::ERROR,
                    ];
            }

            $movement = $this->movementRepository->create($companyId, [
                'inventory_id'  => $inventoryId,
                'type'          => $type,
                'quantity'      => $quantity,
                'unit_price'    => $unitPrice,
                'balance_after' => $balanceAfter,
                'cost_before'   => $currentAvgCost,
                'cost_after'    => $newAvgCost,
                'description'   => $data['description'] ?? null,
                'reference'     => $data['reference'] ?? null,
                'serial_number' => $data['serial_number'] ?? null,
                'batch_number'  => $data['batch_number'] ?? null,
                'expiry_date'   => $data['expiry_date'] ?? null,
                'user_id'       => $userId,
            ]);

            DB::commit();

            return [
                'message' => 'Movimiento registrado correctamente',
                'data'    => [
                    'movement'    => $movement,
                    'item'        => $item->fresh(),
                    'balance_after' => $balanceAfter,
                ],
                'status'  => ApiResponseConstants::SUCCESS,
            ];
        } catch (\Throwable $e) {
            DB::rollBack();
            return [
                'message' => 'Error al registrar movimiento: ' . $e->getMessage(),
                'data'    => null,
                'status'  => ApiResponseConstants::ERROR,
            ];
        }
    }
}
