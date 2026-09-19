<?php

namespace App\Console\Commands;

use App\Models\CobranzaConfig;
use App\Services\Cobranza\Cobranza;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/** El ciclo de cobranza de cada empresa que la tiene activa. */
class CobranzaRevisar extends Command
{
    protected $signature = 'cobranza:revisar {--empresa= : sólo esta empresa}';
    protected $description = 'Detecta clientes con deuda, cierra lo pagado y deja que el asistente escriba en horario';

    public function handle(): int
    {
        $empresas = CobranzaConfig::where('activa', true)
            ->when($this->option('empresa'), fn ($q, $id) => $q->where('company_id', (int) $id))
            ->pluck('company_id');

        foreach ($empresas as $companyId) {
            // Una pasada por empresa a la vez: el asistente tarda y no se debe
            // escribir dos veces al mismo cliente.
            $candado = Cache::lock("cobranza:empresa:{$companyId}", 900);

            if (!$candado->get()) {
                continue;
            }

            try {
                $r = (new Cobranza((int) $companyId))->revisar();

                if (array_sum($r) > 0) {
                    $this->line("Empresa {$companyId}: " . json_encode($r));
                    Log::info('[Cobranza] Revisión', ['empresa' => $companyId] + $r);
                }
            } catch (\Throwable $e) {
                Log::error('[Cobranza] Falló la revisión', ['empresa' => $companyId, 'error' => $e->getMessage()]);
            } finally {
                $candado->release();
            }
        }

        return self::SUCCESS;
    }
}
