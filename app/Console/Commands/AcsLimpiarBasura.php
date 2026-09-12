<?php

namespace App\Console\Commands;

use App\Services\Acs\GenieAcs;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Saca del servidor TR-069 los "equipos" que registran los escáneres.
 *
 * El puerto de los equipos tiene que estar abierto a internet para que las ONT
 * lleguen, y eso lo encuentran los que barren internet: se anuncian como
 * equipos falsos y ensucian el inventario. Se reconocen por el fabricante que
 * declaran y porque nunca vuelven a reportar.
 */
class AcsLimpiarBasura extends Command
{
    protected $signature = 'acs:limpiar {--dias=2 : Sin reportar hace estos días} {--seco : Sólo mostrar}';

    protected $description = 'Borra del ACS los equipos falsos de los escáneres';

    /** Fabricantes que no existen: los inventan las herramientas de barrido. */
    private const FALSOS = ['DISCOVERYSERVICE', 'probe', 'ACME Networks', 'test', 'TEST'];

    public function handle(): int
    {
        $api    = GenieAcs::make();
        $limite = now()->subDays((int) $this->option('dias'));
        $base   = rtrim((string) config('services.genieacs.nbi'), '/');
        $borrados = 0;

        foreach ($api->dispositivos([], ['_id', '_deviceId', '_lastInform']) as $d) {
            $fabricante = $d['_deviceId']['_Manufacturer'] ?? '';
            $ultimo     = $d['_lastInform'] ?? null;

            if (!in_array($fabricante, self::FALSOS, true)) {
                continue;
            }

            // Uno que sigue reportando podría ser un equipo real mal declarado.
            if ($ultimo && now()->parse($ultimo)->gt($limite)) {
                continue;
            }

            $this->line(" - {$fabricante} · " . ($d['_id'] ?? ''));

            if (!$this->option('seco')) {
                Http::timeout(10)->delete($base . '/devices/' . rawurlencode($d['_id']));
                $borrados++;
            }
        }

        $this->info($this->option('seco') ? 'Nada borrado (modo seco).' : "{$borrados} equipos falsos borrados.");

        if ($borrados) {
            Log::info('[ACS] Limpieza de equipos falsos', ['borrados' => $borrados]);
        }

        return self::SUCCESS;
    }
}
