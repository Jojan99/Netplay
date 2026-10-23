<?php

namespace App\Console\Commands;

use App\Models\TvPanel;
use App\Services\Tv\VigiaDeCanales;
use Illuminate\Console\Command;

/** Revisa los canales de cada panel de TV y avisa al grupo los que se caen y los que vuelven. */
class TvCanales extends Command
{
    protected $signature = 'tv:canales';

    protected $description = 'Vigila los canales del panel de TV y avisa al grupo cuando se caen';

    public function handle(): int
    {
        foreach (TvPanel::all() as $panel) {
            session(['user' => (object) ['company_id' => $panel->company_id]]);
            try {
                $r = (new VigiaDeCanales($panel))->revisar();
                $panel->update(['ultima_conexion_en' => now(), 'ultimo_error' => null]);
                $this->info("Empresa {$panel->company_id}: {$r['al_aire']} al aire, {$r['fuera']} fuera · {$r['abiertas']} alertas nuevas, {$r['cerradas']} cerradas");
            } catch (\Throwable $e) {
                $panel->update(['ultimo_error' => \Illuminate\Support\Str::limit($e->getMessage(), 250)]);
                $this->error("Empresa {$panel->company_id}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
