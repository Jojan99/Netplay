<?php

namespace App\Console\Commands;

use App\Services\OltConnectionFactory;
use App\Services\OltTelnetDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

/**
 * Prueba que el worker Telnet reutiliza la sesión entre consultas.
 *
 * Uso:
 *   # Terminal 1 — arranca el worker
 *   php artisan olt:worker {olt_id}
 *
 *   # Terminal 2 — lanza N consultas y mide el tiempo
 *   php artisan olt:test {olt_id}
 *   php artisan olt:test {olt_id} --times=5
 */
class TestOltWorker extends Command
{
    protected $signature   = 'olt:test {olt_id : ID de la OLT} {--times=3 : Número de consultas a lanzar}';
    protected $description = 'Prueba la reutilización de sesión Telnet del OLT worker.';

    public function handle(OltTelnetDispatcher $dispatcher): int
    {
        $oltId  = (int)  $this->argument('olt_id');
        $times  = (int)  $this->option('times');

        $workerAlive = (bool) Redis::exists("olt:{$oltId}:worker_alive");

        $this->info("=== OLT Worker Test — OLT #{$oltId} ===");
        $this->line('');
        $this->line('Worker activo : ' . ($workerAlive ? '<fg=green>SÍ</> (se reutilizará la sesión)' : '<fg=yellow>NO</> (cada consulta abre conexión directa)'));
        $this->line("Consultas     : {$times}");
        $this->line('');

        if (!$workerAlive) {
            $this->warn('Tip: para activar el worker ejecuta en otra terminal:');
            $this->warn("     php artisan olt:worker {$oltId}");
            $this->line('');
        }

        $totalStart = microtime(true);

        for ($i = 1; $i <= $times; $i++) {
            $start = microtime(true);

            try {
                $version = $dispatcher->dispatch($oltId, 'getVersion');
                $ms      = round((microtime(true) - $start) * 1000);

                $this->line("Consulta #{$i}  <fg=green>OK</>  {$ms} ms");

                // Solo muestra el output de la primera consulta para no saturar la pantalla
                if ($i === 1) {
                    $preview = substr(trim($version), 0, 300);
                    $this->line('');
                    $this->line('<fg=gray>--- Respuesta OLT (primeras 300 chars) ---</>');
                    $this->line($preview);
                    $this->line('<fg=gray>------------------------------------------</>');
                    $this->line('');
                }
            } catch (\Throwable $e) {
                $ms = round((microtime(true) - $start) * 1000);
                $this->line("Consulta #{$i}  <fg=red>ERROR</>  {$ms} ms  — {$e->getMessage()}");
            }
        }

        $totalMs = round((microtime(true) - $totalStart) * 1000);

        $this->line('');
        $this->line("Tiempo total  : {$totalMs} ms");
        $this->line("Promedio      : " . round($totalMs / $times) . " ms/consulta");
        $this->line('');

        if ($workerAlive) {
            $this->info('Si los tiempos de las consultas 2+ fueron mucho menores que la 1ra,');
            $this->info('la sesión Telnet se está reutilizando correctamente.');
        }

        return 0;
    }
}
