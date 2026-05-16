<?php

namespace App\Console\Commands;

use App\Models\Inventory;
use App\Models\InventoryMovement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class InventoryRecalculateBalances extends Command
{
    protected $signature = 'inventory:recalculate-balances
                            {--company= : ID de empresa específica}
                            {--dry-run : Solo mostrar cambios sin aplicar}';

    protected $description = 'Recalcula balance_after de movimientos y quantity de ítems por empresa';

    public function handle(): int
    {
        $companyId = $this->option('company');
        $dryRun = $this->option('dry-run');

        $query = Inventory::query();
        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        $totalItems = $query->count();
        $this->info("Procesando {$totalItems} ítems...");

        $bar = $this->output->createProgressBar($totalItems);
        $bar->start();

        $query->chunkById(100, function ($items) use ($dryRun, $bar) {
            foreach ($items as $item) {
                $this->processItem($item, $dryRun);
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info('Recálculo completado.');

        return self::SUCCESS;
    }

    private function processItem(Inventory $item, bool $dryRun): void
    {
        $movements = InventoryMovement::where('inventory_id', $item->id)
            ->where('company_id', $item->company_id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        if ($movements->isEmpty()) {
            return;
        }

        $runningQty = 0;
        $runningCost = 0;

        foreach ($movements as $movement) {
            $qty = (float) $movement->quantity;
            $unitPrice = (float) $movement->unit_price;

            switch ($movement->type) {
                case InventoryMovement::TYPE_ENTRADA:
                    $runningCost = (($runningQty * $runningCost) + ($qty * $unitPrice)) / max($runningQty + $qty, 1);
                    $runningQty += $qty;
                    break;
                case InventoryMovement::TYPE_SALIDA:
                    $runningQty = max($runningQty - $qty, 0);
                    break;
                case InventoryMovement::TYPE_AJUSTE:
                    $runningQty = $qty;
                    break;
            }

            $updateData = [
                'balance_after' => round($runningQty, 2),
                'cost_before'   => round((float) $movement->cost_before, 2),
                'cost_after'    => round($runningCost, 2),
            ];

            if (!$dryRun) {
                $movement->update($updateData);
            }
        }

        if (!$dryRun) {
            $item->update([
                'quantity'     => round($runningQty, 2),
                'average_cost' => round($runningCost, 2),
            ]);
        }
    }
}
