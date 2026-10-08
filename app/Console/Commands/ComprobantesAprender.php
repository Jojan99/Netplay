<?php

namespace App\Console\Commands;

use App\Services\Comprobantes\SemaforoDeComprobantes;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Vuelve a aprender, con los comprobantes aprobados, cómo es uno normal: la curva
 * referencia-hora de Nequi y las cuentas de la empresa. Cada noche, así cada
 * aprobación (y cada rechazo con su motivo) afina el semáforo del día siguiente.
 */
class ComprobantesAprender extends Command
{
    protected $signature = 'comprobantes:aprender {empresa? : Sólo esta empresa}';

    protected $description = 'Recalcula el semáforo de autenticidad de comprobantes con lo aprobado';

    public function handle(SemaforoDeComprobantes $semaforo): int
    {
        $empresas = $this->argument('empresa')
            ? [(int) $this->argument('empresa')]
            : DB::table('payment_proofs')->distinct()->pluck('company_id')->all();

        foreach ($empresas as $empresa) {
            $m = $semaforo->aprender((int) $empresa);
            $this->line("Empresa {$empresa}: " . count($m['puntos']) . ' puntos referencia-hora, ' . count($m['cuentas']) . ' cuentas de la empresa.');
        }

        return self::SUCCESS;
    }
}
