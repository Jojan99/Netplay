<?php

namespace App\Console\Commands;

use App\Services\Red\MacDeIpFija;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Corrige solo la MAC del ARP de los clientes de IP fija cuya ONT cambió de MAC (ver MacDeIpFija). */
class RedRevisarMacs extends Command
{
    protected $signature = 'red:revisar-macs {--empresa= : Sólo esta empresa}';

    protected $description = 'Corrige la MAC del ARP de los clientes de IP fija que dejaron de contestar porque su ONT cambió de MAC';

    public function handle(): int
    {
        $empresas = $this->option('empresa')
            ? [(int) $this->option('empresa')]
            : DB::table('gestion_remota')->where('activa', 1)->pluck('company_id')->map(fn ($c) => (int) $c)->all();

        foreach ($empresas as $empresa) {
            $r = (new MacDeIpFija($empresa))->revisar();
            $this->line("Empresa {$empresa}: {$r['revisados']} revisados, {$r['sin_respuesta']} sin respuesta, " . count($r['corregidos']) . ' corregidos.');

            foreach ($r['corregidos'] as $c) {
                $this->info("  ✓ {$c}");
            }
            foreach ($r['errores'] as $e) {
                $this->warn("  ! {$e}");
            }
        }

        return self::SUCCESS;
    }
}
