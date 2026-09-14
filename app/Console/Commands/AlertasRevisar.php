<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Alertas\RevisorDeRed;
use Illuminate\Console\Command;

/** Revisa la red de cada empresa y deja anotado lo que hay que mirar. */
class AlertasRevisar extends Command
{
    protected $signature = 'alertas:revisar {empresa? : Sólo esta empresa}';

    protected $description = 'Revisa señal óptica, cortes de puerto PON, túneles y OLT';

    public function handle(): int
    {
        $empresas = $this->argument('empresa')
            ? [(int) $this->argument('empresa')]
            : Company::pluck('id')->all();

        foreach ($empresas as $companyId) {
            $r = (new RevisorDeRed((int) $companyId))->revisar();

            // Lo crítico nuevo y lo resuelto, al grupo de los técnicos (si la
            // empresa asoció uno).
            $g = (new \App\Services\Alertas\AvisosAlGrupo((int) $companyId))->enviarPendientes();

            $this->line("Empresa {$companyId}: {$r['abiertas']} avisos abiertos, {$r['cerradas']} cerrados."
                . ($g['abiertas'] || $g['resueltas'] ? " Al grupo: {$g['abiertas']} nuevas, {$g['resueltas']} resueltas." : ''));
        }

        return self::SUCCESS;
    }
}
