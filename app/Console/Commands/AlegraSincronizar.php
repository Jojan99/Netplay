<?php

namespace App\Console\Commands;

use App\Models\FacturaElectronicaConfig;
use App\Services\Alegra\EspejoDeAlegra;
use Illuminate\Console\Command;

/**
 * Refresca la copia local de lo que cada empresa tiene en Alegra. Sólo lee de Alegra.
 */
class AlegraSincronizar extends Command
{
    protected $signature = 'alegra:sincronizar {empresa? : Id de la empresa; sin él, todas las que tienen Alegra conectado}';

    protected $description = 'Trae facturas, pagos y contactos de Alegra y los cruza con Netvula (sólo lectura)';

    public function handle(): int
    {
        $empresas = $this->argument('empresa')
            ? [(int) $this->argument('empresa')]
            : FacturaElectronicaConfig::where('proveedor', 'alegra')->pluck('company_id')->all();

        foreach ($empresas as $empresa) {
            $espejo = new EspejoDeAlegra((int) $empresa);

            if (!$espejo->conectado()) {
                continue;
            }

            $e = $espejo->sincronizar();

            // Con la copia al día se arma la bandeja. Sólo se aprueba —y se escribe en Alegra—
            // lo que el administrador dejó en automático; lo demás queda esperando a una persona.
            if (($e['estado'] ?? '') === 'listo') {
                $ops = new \App\Services\Alegra\OperacionesDeAlegra((int) $empresa);
                $ops->proponer();

                if ($ops->aprobarAutomaticas() > 0) {
                    $ops->aplicarAprobadas();
                }
            }
            $this->line("Empresa {$empresa}: " . ($e['estado'] ?? '?') . ' · ' . ($e['facturas'] ?? 0) . ' facturas, ' . ($e['pagos'] ?? 0) . ' pagos, ' . ($e['contactos'] ?? 0) . ' contactos' . (!empty($e['error']) ? ' · ' . $e['error'] : ''));
        }

        return self::SUCCESS;
    }
}
