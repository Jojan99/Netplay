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
        // La cuenta, el candado y de dónde sale el equipo (de la bodega o de lo que ya tenía
        // el técnico) los resuelve Bodega, que es el único lugar que mueve existencias.
        return (new \App\Services\Inventario\Bodega($companyId))->instalar($serial, $ordenId, $userId, $tecnicoId, $inventoryId);
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
        return \App\Services\Inventario\Bodega::renglonesDeOnt($companyId);
    }
}
