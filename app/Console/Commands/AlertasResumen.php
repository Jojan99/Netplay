<?php

namespace App\Console\Commands;

use App\Services\Alertas\AvisosAlGrupo;
use Illuminate\Console\Command;

/** Resumen de la mañana de la red, al grupo de alertas de cada empresa. */
class AlertasResumen extends Command
{
    protected $signature = 'alertas:resumen {empresa? : Sólo esta empresa}';

    protected $description = 'Manda al grupo de WhatsApp el resumen de alertas abiertas';

    public function handle(): int
    {
        $empresas = $this->argument('empresa')
            ? [(int) $this->argument('empresa')]
            : AvisosAlGrupo::empresasConAlertas();

        foreach ($empresas as $companyId) {
            $enviado = (new AvisosAlGrupo((int) $companyId))->enviarResumen();
            $this->line("Empresa {$companyId}: " . ($enviado ? 'resumen enviado.' : 'sin grupo o no se pudo enviar.'));
        }

        return self::SUCCESS;
    }
}
