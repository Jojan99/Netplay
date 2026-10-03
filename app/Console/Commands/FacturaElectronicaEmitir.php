<?php

namespace App\Console\Commands;

use App\Models\FacturaElectronicaConfig;
use App\Services\FacturaElectronica\FacturacionElectronica;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/** Emite ante la DIAN lo que cada empresa cobró y aún no tiene factura electrónica. */
class FacturaElectronicaEmitir extends Command
{
    protected $signature = 'factura-electronica:emitir {--empresa= : sólo esta empresa}';
    protected $description = 'Emite las facturas electrónicas pendientes de las empresas que lo tienen en automático';

    public function handle(): int
    {
        $empresas = FacturaElectronicaConfig::where('activa', true)
            ->when($this->option('empresa'), fn ($q, $id) => $q->where('company_id', (int) $id))
            ->pluck('company_id');

        foreach ($empresas as $companyId) {
            // Una tanda por empresa a la vez: una tanda lenta no se pisa con la siguiente.
            $candado = Cache::lock("fe:barrido:{$companyId}", 600);

            if (!$candado->get()) {
                continue;
            }

            try {
                $r = (new FacturacionElectronica((int) $companyId))->barrer();

                if (array_sum($r) > 0) {
                    $this->info("Empresa {$companyId}: " . json_encode($r));
                    Log::info('[Factura electrónica] Barrido', ['empresa' => $companyId] + $r);
                }
            } catch (\Throwable $e) {
                $this->warn("Empresa {$companyId}: " . $e->getMessage());
                Log::warning('[Factura electrónica] Falló el barrido', ['empresa' => $companyId, 'error' => $e->getMessage()]);
            } finally {
                $candado->release();
            }
        }

        return self::SUCCESS;
    }
}
