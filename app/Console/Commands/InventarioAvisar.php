<?php

namespace App\Console\Commands;

use App\Services\Inventario\AvisosDeInventario;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * La pasada diaria del inventario: avisa lo que está en el mínimo y nadie avisó, y cierra las
 * alertas de lo que ya se repuso. Los lunes manda además qué técnicos tienen equipos hace tiempo.
 */
class InventarioAvisar extends Command
{
    protected $signature = 'inventory:avisar {empresa? : Id de la empresa; sin él, todas las que tienen inventario} {--tecnicos : Mandar también el resumen de técnicos}';

    protected $description = 'Avisa existencias bajas y equipos que llevan tiempo en manos de técnicos';

    public function handle(): int
    {
        $empresas = $this->argument('empresa')
            ? [(int) $this->argument('empresa')]
            : DB::table('inventories')->whereNull('deleted_at')->distinct()->pluck('company_id')->all();

        foreach ($empresas as $empresa) {
            $r = AvisosDeInventario::revisar((int) $empresa);
            $resumen = ($this->option('tecnicos') || now()->isMonday()) && AvisosDeInventario::resumenDeTecnicos((int) $empresa);

            $this->line("Empresa {$empresa}: {$r['avisados']} aviso(s) nuevos, {$r['cerradas']} alerta(s) cerradas" . ($resumen ? ', resumen de técnicos enviado' : ''));
        }

        return self::SUCCESS;
    }
}
