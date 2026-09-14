<?php

namespace App\Console\Commands;

use App\Models\Company;
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
            : Company::whereNotNull('alertas_grupo_jid')->pluck('id')->all();

        foreach ($empresas as $companyId) {
            $enviado = (new AvisosAlGrupo((int) $companyId))->enviarResumen();
            $this->line("Empresa {$companyId}: " . ($enviado ? 'resumen enviado.' : 'sin grupo o no se pudo enviar.'));
        }

        return self::SUCCESS;
    }
}
