<?php

namespace App\Console\Commands;

use App\Models\OltAdmin;
use App\OltDrivers\HuaweiOltDriver;
use App\Services\OltConnectionFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Worker persistente que mantiene UNA sesión Telnet por OLT.
 *
 * Arrancar con supervisor:
 *   php artisan olt:worker {olt_id}
 *
 * El worker:
 *  - Conecta a la OLT cuando llega el primer comando (lazy).
 *  - Reutiliza la misma sesión para todos los comandos siguientes.
 *  - Cierra la sesión tras 5 minutos de inactividad (pero el proceso sigue vivo).
 *  - Reconecta automáticamente si llega un nuevo comando después del cierre.
 *  - Publica un heartbeat en Redis (TTL 15 s) para que el Dispatcher
 *    sepa si el worker está activo antes de encolar un comando.
 */
class OltTelnetWorker extends Command
{
    protected $signature   = 'olt:worker {olt_id : ID de la OLT}';
    protected $description = 'Mantiene una sesión Telnet persistente con la OLT y atiende comandos via Redis.';

    private const IDLE_TIMEOUT  = 300; // segundos — cierra la sesión tras 5 min sin comandos
    private const HEARTBEAT_TTL = 15;  // TTL del heartbeat en Redis
    private const BLPOP_TIMEOUT = 5;   // segundos que espera por un comando antes de re-loop
    private const SAVE_DELAY    = 30;  // segundos de inactividad tras escritura antes de auto-save

    private const WRITE_METHODS = [
        'registerONT', 'deleteONT', 'assignToClient',
        'transferONT', 'deactivateONT', 'activateONT',
    ];

    private ?object          $connection     = null;
    private ?HuaweiOltDriver $driver         = null;
    private float            $lastActivityAt = 0.0;
    private bool             $pendingSave    = false;
    private float            $lastWriteAt    = 0.0;

    public function handle(OltConnectionFactory $factory): int
    {
        $oltId    = (int) $this->argument('olt_id');
        $queueKey = "olt:{$oltId}:cmd_queue";

        $this->info("OLT Worker #{$oltId} iniciado — esperando comandos en Redis [{$queueKey}]");
        $this->lastActivityAt = microtime(true);

        // Publicar heartbeat inmediatamente para que el dispatcher sepa que estamos vivos
        // antes de intentar la conexión (que puede tardar 30-40s en cold start)
        $this->publishHeartbeat($oltId);

        // Conectar proactivamente al arrancar — así el primer comando no espera el cold-start
        try {
            $this->ensureConnected($oltId, $factory);
        } catch (\Throwable $e) {
            Log::warning("OLT Worker #{$oltId}: conexión inicial falló, se reintentará en el primer comando", [
                'error' => $e->getMessage(),
            ]);
            $this->connection = null;
            $this->driver     = null;
        }

        while (true) {
            $this->publishHeartbeat($oltId);
            $this->checkIdleTimeout($oltId);

            // Espera hasta BLPOP_TIMEOUT segundos por un comando
            $item = Redis::blpop($queueKey, self::BLPOP_TIMEOUT);

            if ($item === null) {
                $this->checkPendingSave($oltId);
                continue;
            }

            $command   = json_decode($item[1], true);
            $resultKey = $command['result_key'] ?? null;

            if (!$resultKey) {
                Log::warning("OLT Worker #{$oltId}: comando sin result_key, ignorado", $command);
                continue;
            }

            try {
                $this->ensureConnected($oltId, $factory);
                $data = $this->executeCommand($command);
                Redis::rpush($resultKey, json_encode(['success' => true, 'data' => $data]));

                if (in_array($command['method'], self::WRITE_METHODS, true)) {
                    $this->pendingSave = true;
                    $this->lastWriteAt = microtime(true);
                }
            } catch (\Throwable $e) {
                Log::error("OLT Worker #{$oltId}: error en {$command['method']}", ['error' => $e->getMessage()]);
                $this->disconnect($oltId); // resetear estado para forzar reconexión
                Redis::rpush($resultKey, json_encode(['success' => false, 'error' => $e->getMessage()]));
            } finally {
                Redis::expire($resultKey, 60);
                $this->lastActivityAt = microtime(true);
            }
        }
    }

    // ── Conexión ──────────────────────────────────────────────────────────

    private function ensureConnected(int $oltId, OltConnectionFactory $factory): void
    {
        if ($this->driver !== null) {
            return; // ya conectado — reutilizar
        }

        $olt = OltAdmin::findOrFail($oltId);

        $this->connection = $factory->connect($olt);

        $config                    = $olt->toArray();
        $config['enable_password'] = $olt->enable_password ?? null;

        $this->driver = new HuaweiOltDriver($this->connection, $config);

        Log::info("OLT Worker #{$oltId}: sesión Telnet establecida");
        $this->info("OLT #{$oltId}: sesión abierta");
    }

    private function checkIdleTimeout(int $oltId): void
    {
        if ($this->driver === null) {
            return; // ya desconectado
        }

        if ((microtime(true) - $this->lastActivityAt) < self::IDLE_TIMEOUT) {
            return;
        }

        $this->disconnect($oltId);
        Log::info("OLT Worker #{$oltId}: sesión cerrada por 5 min de inactividad");
        $this->info("OLT #{$oltId}: sesión cerrada por inactividad");
        $this->lastActivityAt = microtime(true); // evitar que el check se dispare en bucle
    }

    private function disconnect(int $oltId): void
    {
        if ($this->connection) {
            try {
                if (method_exists($this->connection, 'close')) {
                    $this->connection->close();
                } elseif (method_exists($this->connection, 'disconnect')) {
                    $this->connection->disconnect();
                }
            } catch (\Throwable) {}
        }

        $this->connection = null;
        $this->driver     = null;
        Log::info("OLT Worker #{$oltId}: conexión cerrada");
    }

    private function publishHeartbeat(int $oltId): void
    {
        Redis::setex("olt:{$oltId}:worker_alive", self::HEARTBEAT_TTL, '1');
    }

    private function checkPendingSave(int $oltId): void
    {
        if (!$this->pendingSave || $this->driver === null) {
            return;
        }

        if ((microtime(true) - $this->lastWriteAt) < self::SAVE_DELAY) {
            return;
        }

        Log::info("OLT Worker #{$oltId}: iniciando auto-save (30s sin cambios)");
        $this->info("OLT #{$oltId}: guardando configuración en flash...");

        try {
            $this->driver->saveConfig();
            $this->pendingSave = false;
            Log::info("OLT Worker #{$oltId}: auto-save completado");
            $this->info("OLT #{$oltId}: configuración guardada.");
        } catch (\Throwable $e) {
            Log::error("OLT Worker #{$oltId}: auto-save falló, reintentando en 30s", [
                'error' => $e->getMessage(),
            ]);
            $this->lastWriteAt = microtime(true); // reinicia el contador para reintentar
        }
    }

    // ── Ejecución de comandos ─────────────────────────────────────────────

    private function executeCommand(array $command): mixed
    {
        $method = $command['method'];
        $p      = $command['params'] ?? [];

        return match ($method) {
            'getVersion'        => $this->driver->getVersion(),
            'getUnauthONTs'     => $this->driver->getUnauthONTs(),
            'getAuthorizedONTs' => $this->driver->getAuthorizedONTs(),
            'getOntInfo'        => $this->driver->getOntInfo(
                                       $p['fsp'],
                                       (int) $p['ont_id']
                                   ),
            'getServicePorts'   => $this->driver->getServicePorts(
                                       $p['fsp']    ?? null,
                                       isset($p['ont_id']) ? (int) $p['ont_id'] : null
                                   ),
            'getLineProfiles'   => $this->driver->getLineProfiles(),
            'getSrvProfiles'    => $this->driver->getSrvProfiles(),
            'registerONT'       => $this->driver->registerONT(
                                       $p['fsp'],
                                       $p['serial'],
                                       $p['description'] ?? $p['serial'],
                                       isset($p['line_profile_id']) ? (int) $p['line_profile_id'] : null,
                                       isset($p['srv_profile_id'])  ? (int) $p['srv_profile_id']  : null,
                                       isset($p['vlan'])            ? (int) $p['vlan']            : null,
                                       isset($p['service_port'])    ? (int) $p['service_port']    : null,
                                   ),
            'deleteONT'         => $this->driver->deleteONT(
                                       $p['fsp'],
                                       (int) $p['ont_id'],
                                       (array) ($p['service_ports'] ?? [])
                                   ),
            'assignToClient'    => $this->driver->assignToClient(
                                       $p['fsp'],
                                       (int) $p['ont_id'],
                                       (int) $p['vlan'],
                                       (int) $p['service_port'],
                                       $p['description'] ?? ''
                                   ),
            'transferONT'       => $this->driver->transferONT(
                                       $p['from_fsp'],
                                       (int) $p['ont_id'],
                                       $p['to_fsp']
                                   ),
            'deactivateONT'     => $this->driver->deactivateONT($p['fsp'], (int) $p['ont_id']),
            'activateONT'       => $this->driver->activateONT($p['fsp'], (int) $p['ont_id']),
            'runCommand'        => $this->driver->runCommand($p['command']),
            default             => throw new \RuntimeException("Método OLT desconocido: {$method}"),
        };
    }
}
