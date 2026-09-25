<?php

namespace App\Services\Instalaciones;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sacar del stock el equipo que quedó instalado.
 *
 * El inventario lleva cantidades, no equipos uno por uno: hay «ONT Huawei
 * HG8145, 40 unidades». El serial vive en el movimiento, que es donde tiene
 * sentido —es el registro de que ESE equipo salió, a qué casa y a qué hora—.
 *
 * Si no se puede descontar, la instalación sigue igual. El cliente ya tiene
 * internet; que el stock no cuadre es un problema de oficina, y frenar a un
 * técnico que está en una casa por eso no le sirve a nadie.
 */
class EquipoDelInventario
{
    /**
     * @return array{ok: bool, detalle: string, inventory_id: ?int}
     */
    public static function descontar(
        int $companyId,
        string $serial,
        int $userId,
        int $ordenId,
        ?int $inventoryId = null,
        ?int $tecnicoId = null,
    ): array {
        $item = $inventoryId
            ? DB::table('inventories')->where('company_id', $companyId)->where('id', $inventoryId)->first()
            : self::adivinarElItem($companyId, $serial);

        if (!$item) {
            return [
                'ok' => false,
                'detalle' => "El serial {$serial} no se encontró en el inventario. El equipo quedó instalado igual; revisá el stock desde la oficina.",
                'inventory_id' => null,
            ];
        }

        if ((float) $item->quantity <= 0) {
            return [
                'ok' => false,
                'detalle' => "«{$item->name}» figura en cero en el inventario. El equipo quedó instalado igual.",
                'inventory_id' => (int) $item->id,
            ];
        }

        try {
            DB::transaction(function () use ($item, $companyId, $serial, $userId, $ordenId, $tecnicoId) {
                $quedan = (float) $item->quantity - 1;

                DB::table('inventories')->where('id', $item->id)->update([
                    'quantity'   => $quedan,
                    'updated_at' => now(),
                ]);

                DB::table('inventory_movements')->insert([
                    'company_id'    => $companyId,
                    'inventory_id'  => $item->id,
                    'type'          => 'salida',
                    'quantity'      => 1,
                    'balance_after' => $quedan,
                    'unit_price'    => $item->unit_price,
                    'description'   => 'Instalada en casa del cliente',
                    // Con esto se llega de vuelta a la orden y al cliente sin
                    // tener que cruzar tablas a mano.
                    'reference'     => "instalacion:{$ordenId} cliente:{$userId}",
                    'serial_number' => $serial,
                    'user_id'       => $tecnicoId,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);
            });
        } catch (\Throwable $e) {
            Log::warning('[Instalación] No se pudo descontar del inventario', [
                'serial' => $serial, 'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'detalle' => 'No se pudo descontar del inventario: ' . $e->getMessage(), 'inventory_id' => (int) $item->id];
        }

        return [
            'ok' => true,
            'detalle' => "«{$item->name}», serial {$serial}. Quedan " . ((float) $item->quantity - 1) . " en stock.",
            'inventory_id' => (int) $item->id,
        ];
    }

    /**
     * De qué renglón del inventario salió este equipo.
     *
     * Primero se busca el serial en un movimiento anterior —la entrada, cuando
     * se cargó el lote—, que es el dato exacto. Si no está, se busca un único
     * renglón de ONT con stock: con uno solo no hay ambigüedad, con varios se
     * prefiere no adivinar y que lo elija una persona.
     */
    private static function adivinarElItem(int $companyId, string $serial): ?object
    {
        $porSerial = DB::table('inventory_movements')
            ->where('company_id', $companyId)
            ->where('serial_number', $serial)
            ->orderByDesc('id')
            ->value('inventory_id');

        if ($porSerial) {
            return DB::table('inventories')->where('id', $porSerial)->first();
        }

        $onts = DB::table('inventories')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->where('quantity', '>', 0)
            ->where(fn ($q) => $q->where('name', 'like', '%ont%')->orWhere('name', 'like', '%onu%'))
            ->get();

        return $onts->count() === 1 ? $onts->first() : null;
    }

    /**
     * Los renglones de ONT con stock, para que el técnico elija cuando no se
     * puede adivinar.
     *
     * @return list<array<string,mixed>>
     */
    public static function ontsConStock(int $companyId): array
    {
        return DB::table('inventories')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->where('quantity', '>', 0)
            ->where(fn ($q) => $q->where('name', 'like', '%ont%')->orWhere('name', 'like', '%onu%'))
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'quantity'])
            ->map(fn ($i) => (array) $i)
            ->all();
    }
}
