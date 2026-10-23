<?php

namespace App\Console\Commands;

use App\Models\TvCanal;
use App\Models\TvPanel;
use App\Services\Tv\PreviasDeCanales;
use Illuminate\Console\Command;

/** Una foto de cada canal al aire, para la cuadrícula de Televisión: seguido si alguien mira, si no cada hora. */
class TvPrevias extends Command
{
    protected $signature = 'tv:previas';

    protected $description = 'Toma una foto de cada canal al aire para las previas de Televisión';

    public function handle(): int
    {
        foreach (TvPanel::all() as $panel) {
            if (!PreviasDeCanales::tocaTomar((int) $panel->company_id)) {
                continue; // Nadie está mirando y las fotos tienen menos de una hora.
            }
            try {
                $r = (new PreviasDeCanales($panel))->tomarFotos();
                PreviasDeCanales::limpiarViejas((int) $panel->company_id, TvCanal::where('company_id', $panel->company_id)->pluck('stream_id')->map(fn ($v) => (int) $v)->all());
                $this->info("Empresa {$panel->company_id}: {$r['tomadas']} fotos, {$r['fallidas']} sin imagen");
            } catch (\Throwable $e) {
                $this->error("Empresa {$panel->company_id}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
