<?php

namespace App\Console\Commands;

use App\Managers\Interfaces\ConectionRouterManagerInterface;
use App\Services\Red\SincronizarIpsDesdeRouter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Deja la IP de cada cliente igual a la que tiene en el MikroTik.
 *
 * Pensado para correr por cron. Con --simular no escribe nada y sólo muestra
 * qué cambiaría, que es como conviene mirarlo la primera vez.
 */
class SincronizarIpsCommand extends Command
{
    protected $signature = 'red:sincronizar-ips
        {--empresa= : Sólo esta empresa; por defecto todas}
        {--router= : Sólo este router}
        {--simular : No guarda nada, sólo informa}';

    protected $description = 'Sincroniza la IP de los clientes con la que tienen en el MikroTik';

    public function handle(ConectionRouterManagerInterface $conexion): int
    {
        $simular  = (bool) $this->option('simular');
        $routerId = $this->option('router') ? (int) $this->option('router') : null;

        $empresas = $this->option('empresa')
            ? [(int) $this->option('empresa')]
            : DB::table('conection_routers')->distinct()->pluck('company_id')->all();

        if (!$empresas) {
            $this->warn('No hay empresas con routers configurados.');
            return self::SUCCESS;
        }

        foreach ($empresas as $companyId) {
            $this->line("── empresa {$companyId} " . ($simular ? '(simulación)' : ''));

            $r = (new SincronizarIpsDesdeRouter($conexion, (int) $companyId))->ejecutar($simular, $routerId);

            foreach ($r['routers'] as $router) {
                $router['ok']
                    ? $this->line("   {$router['router']}: {$router['entradas']} entradas ARP con documento")
                    : $this->warn("   {$router['router']}: no respondió");
            }

            $faltaban = array_filter($r['cambios'], fn ($c) => $c['tipo'] === 'faltaba');
            $cambios  = array_filter($r['cambios'], fn ($c) => $c['tipo'] === 'cambio');

            $this->line('   ' . count($faltaban) . ' clientes sin IP en la plataforma · '
                . count($cambios) . ' con una IP distinta a la del router · '
                . $r['sin_cambio'] . ' ya coincidían');
            $this->line('   ' . count($r['desconocidos']) . ' documentos del router que no están en la plataforma · '
                . count($r['ambiguos']) . ' sin resolver por ambigüedad');

            // Los cambios de IP son los que valen mirar de cerca: ahí la
            // plataforma decía otra cosa, no es un dato que faltaba.
            foreach (array_slice($cambios, 0, 20) as $c) {
                $this->line("      {$c['documento']}  {$c['cliente']}: {$c['antes']} → {$c['ahora']}");
            }

            if (count($cambios) > 20) {
                $this->line('      … y ' . (count($cambios) - 20) . ' cambios más');
            }

            foreach (array_slice($r['ambiguos'], 0, 10) as $a) {
                $this->warn("      ambiguo {$a['documento']}: {$a['motivo']}");
            }

            foreach ($r['errores'] as $e) {
                $this->error("   {$e}");
            }
        }

        return self::SUCCESS;
    }
}
