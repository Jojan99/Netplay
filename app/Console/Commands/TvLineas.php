<?php

namespace App\Console\Commands;

use App\Models\TvPanel;
use App\Services\Tv\LineasDeTv;
use Illuminate\Console\Command;

/**
 * Las líneas de TV al día: trae las del panel y las liga a su cliente, crea las que quedaron
 * pendientes y, si la empresa lo prendió, apaga y prende la TV con la cartera.
 */
class TvLineas extends Command
{
    protected $signature = 'tv:lineas';

    protected $description = 'Sincroniza las líneas de TV con el panel y con la cartera';

    public function handle(): int
    {
        foreach (TvPanel::all() as $panel) {
            session(['user' => (object) ['company_id' => $panel->company_id]]);
            try {
                $s = new LineasDeTv($panel);
                $t = $s->traerDelPanel();
                $p = $s->crearPendientes();
                $c = $s->seguirLaCartera();
                $panel->update(['ultima_conexion_en' => now(), 'ultimo_error' => null]);
                $this->info("Empresa {$panel->company_id}: {$t['lineas']} líneas ({$t['nuevas']} nuevas, {$t['vinculadas']} ligadas, {$t['por_revisar']} por revisar) · {$p} creadas · cartera: {$c['suspendidas']} suspendidas, {$c['reactivadas']} reactivadas, {$c['retiradas']} retiradas");
            } catch (\Throwable $e) {
                $panel->update(['ultimo_error' => \Illuminate\Support\Str::limit($e->getMessage(), 250)]);
                $this->error("Empresa {$panel->company_id}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
