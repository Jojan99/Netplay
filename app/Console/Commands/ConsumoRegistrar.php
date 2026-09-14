<?php

namespace App\Console\Commands;

use App\Services\Red\ConsumoDelCliente;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Suma al día el consumo de datos que reportan los equipos al TR-069. */
class ConsumoRegistrar extends Command
{
    protected $signature = 'consumo:registrar {empresa? : id de la empresa; sin él, todas}';

    protected $description = 'Lee los contadores de las ONT en el TR-069 y suma el consumo del día a cada cliente';

    public function handle(): int
    {
        $empresas = $this->argument('empresa')
            ? [(int) $this->argument('empresa')]
            : DB::table('companies')->pluck('id')->map(fn ($id) => (int) $id)->all();

        foreach ($empresas as $empresa) {
            try {
                $r = (new ConsumoDelCliente($empresa))->registrar();
            } catch (\Throwable $e) {
                $this->warn("Empresa {$empresa}: " . $e->getMessage());
                continue;
            }

            if ($r['equipos'] > 0) {
                $this->info("Empresa {$empresa}: {$r['equipos']} equipos, {$r['con_contador']} con contador, {$r['sumados']} sumaron consumo.");
            }
        }

        return self::SUCCESS;
    }
}
