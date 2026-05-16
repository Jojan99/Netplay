<?php

namespace App\Console\Commands;

use App\Models\Inventory;
use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InventoryLowStockReport extends Command
{
    protected $signature = 'inventory:low-stock-report
                            {--company= : ID de empresa específica}
                            {--email= : Enviar reporte a este email}
                            {--output= : Guardar reporte en archivo}';

    protected $description = 'Genera reporte de ítems con stock bajo por empresa';

    public function handle(): int
    {
        $companyId = $this->option('company');

        $query = Inventory::with('category')
            ->whereRaw('quantity <= stock_min AND stock_min > 0')
            ->orderBy('company_id')
            ->orderBy('quantity', 'asc');

        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        $items = $query->get();

        if ($items->isEmpty()) {
            $this->info('No hay ítems con stock bajo.');
            return self::SUCCESS;
        }

        $rows = $items->map(fn ($i) => [
            'Empresa'    => $i->company_id,
            'SKU'        => $i->sku ?? 'N/A',
            'Nombre'     => $i->name,
            'Categoría'  => $i->category?->name ?? 'N/A',
            'Stock'      => $i->quantity,
            'Mínimo'     => $i->stock_min,
            'Ubicación'  => $i->location ?? 'N/A',
        ])->toArray();

        $this->table(
            ['Empresa', 'SKU', 'Nombre', 'Categoría', 'Stock', 'Mínimo', 'Ubicación'],
            $rows
        );

        $output = $this->option('output');
        if ($output) {
            file_put_contents($output, json_encode($rows, JSON_PRETTY_PRINT));
            $this->info("Reporte guardado en: {$output}");
        }

        Log::channel('daily')->info('inventory:low-stock-report ejecutado', [
            'total' => $items->count(),
            'company' => $companyId ?: 'todas',
        ]);

        return self::SUCCESS;
    }
}
