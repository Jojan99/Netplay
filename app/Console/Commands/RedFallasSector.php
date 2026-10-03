<?php

namespace App\Console\Commands;

use App\Services\Red\FallasDeSector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Revisa las fallas de sector de las empresas que tienen el módulo encendido.
 *
 * Corre cada minuto; cada empresa decide cada cuántos minutos se lee su OLT
 * (fallas_sector_config.minutos_revision). Un candado por empresa evita que dos pasadas
 * le escriban dos veces al mismo cliente si el envío se demora.
 */
class RedFallasSector extends Command
{
    protected $signature = 'red:fallas-sector {--empresa= : Sólo esta empresa} {--forzar : Leer ya, aunque no toque o esté apagado}';

    protected $description = 'Detecta caídas de puertos PON y avisa a los clientes afectados';

    public function handle(): int
    {
        $empresas = $this->option('empresa')
            ? [(int) $this->option('empresa')]
            : DB::table('fallas_sector_config')->where('activo', 1)->pluck('company_id')->map(fn ($i) => (int) $i)->all();

        foreach ($empresas as $empresa) {
            $candado = Cache::store('redis')->lock("fallas-sector:{$empresa}", 900);

            if (!$candado->get()) {
                continue;
            }

            try {
                $r = (new FallasDeSector($empresa))->revisar((bool) $this->option('forzar'));
                if ($r['revisada'] || $this->option('empresa')) {
                    $this->line("empresa {$empresa}: " . json_encode($r, JSON_UNESCAPED_UNICODE));
                }
            } catch (\Throwable $e) {
                $this->error("empresa {$empresa}: {$e->getMessage()}");
            } finally {
                $candado->release();
            }
        }

        return self::SUCCESS;
    }
}
