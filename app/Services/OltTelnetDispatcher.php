<?php

namespace App\Services;

use App\Models\OltAdmin;
use App\OltDrivers\HuaweiOltDriver;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Despacha comandos Telnet a la OLT.
 *
 * Si el worker persistente (OltTelnetWorker) está activo para esa OLT,
 * encola el comando en Redis y espera la respuesta — la sesión se reutiliza.
 *
 * Si el worker no está activo, lo arranca automáticamente en background
 * y espera a que esté listo. Si no levanta en tiempo, cae a conexión directa.
 */
class OltTelnetDispatcher
{
    private const RESULT_TIMEOUT  = 120; // segundos esperando respuesta del worker (cold-start puede tardar 40s)
    private const SPAWN_WAIT      = 15;  // segundos máx esperando que el worker arranque
    private const SPAWN_LOCK_TTL  = 25;  // TTL del lock anti-spawn-duplicado (segundos)

    public function __construct(private OltConnectionFactory $factory) {}

    public function dispatch(int $oltId, string $method, array $params = []): mixed
    {
        if (Redis::exists("olt:{$oltId}:worker_alive")) {
            return $this->viaWorker($oltId, $method, $params);
        }

        // Worker no activo — intentar arrancarlo automáticamente
        if ($this->spawnWorker($oltId)) {
            return $this->viaWorker($oltId, $method, $params);
        }

        // No se pudo arrancar — conexión directa como fallback
        Log::warning("OltTelnetDispatcher: worker no pudo arrancar para OLT #{$oltId}, usando conexión directa");
        return $this->viaDirect($oltId, $method, $params);
    }

    // ── Auto-spawn ────────────────────────────────────────────────────────

    /**
     * Arranca el worker en background si no hay otro haciéndolo ya.
     * Usa un lock Redis para evitar que dos requests simultáneos lancen
     * dos procesos para la misma OLT.
     *
     * @return bool  true si el worker quedó listo, false si falló o tardó mucho
     */
    private function spawnWorker(int $oltId): bool
    {
        $lockKey = "olt:{$oltId}:spawning";

        // SET NX — solo un proceso gana el lock y lanza el worker
        $iAmSpawner = Redis::set($lockKey, 1, 'EX', self::SPAWN_LOCK_TTL, 'NX');

        if ($iAmSpawner) {
            $artisan = base_path('artisan');
            $logFile = storage_path("logs/olt-worker-{$oltId}.log");

            // PHP_BINARY en contexto FPM devuelve el binario de php-fpm, no el CLI.
            // PhpExecutableFinder resuelve correctamente el intérprete CLI.
            $phpBin = (new \Symfony\Component\Process\PhpExecutableFinder())->find() ?: 'php';

            $cmd = sprintf(
                'nohup %s %s olt:worker %d >> %s 2>&1 &',
                escapeshellarg($phpBin),
                escapeshellarg($artisan),
                $oltId,
                escapeshellarg($logFile)
            );

            exec($cmd);
            Log::info("OltTelnetDispatcher: worker arrancado para OLT #{$oltId}", ['cmd' => $cmd]);
        }

        // Todos los requests (el que lanzó y los que esperaban) esperan el heartbeat
        return $this->waitForWorker($oltId);
    }

    /**
     * Espera hasta SPAWN_WAIT segundos a que el worker publique su heartbeat.
     */
    private function waitForWorker(int $oltId): bool
    {
        $deadline = microtime(true) + self::SPAWN_WAIT;

        while (microtime(true) < $deadline) {
            if (Redis::exists("olt:{$oltId}:worker_alive")) {
                return true;
            }
            usleep(200_000); // 200 ms entre checks
        }

        Log::error("OltTelnetDispatcher: timeout esperando worker para OLT #{$oltId}");
        return false;
    }

    // ── Via worker persistente ────────────────────────────────────────────

    private function viaWorker(int $oltId, string $method, array $params): mixed
    {
        $requestId = (string) Str::uuid();
        $resultKey = "olt:{$oltId}:result:{$requestId}";

        Redis::lpush("olt:{$oltId}:cmd_queue", json_encode([
            'request_id' => $requestId,
            'result_key' => $resultKey,
            'method'     => $method,
            'params'     => $params,
        ]));

        $result = Redis::blpop($resultKey, self::RESULT_TIMEOUT);

        if ($result === null) {
            $timeout = self::RESULT_TIMEOUT;
            throw new \RuntimeException(
                "Timeout ({$timeout}s) esperando respuesta del OLT worker para '{$method}'."
            );
        }

        $decoded = json_decode($result[1], true);

        if (!($decoded['success'] ?? false)) {
            throw new \RuntimeException($decoded['error'] ?? 'Error desconocido en OLT worker');
        }

        return $decoded['data'];
    }

    // ── Fallback: conexión directa (sin worker) ───────────────────────────

    private function viaDirect(int $oltId, string $method, array $params): mixed
    {
        $olt    = OltAdmin::findOrFail($oltId);
        $conn   = $this->factory->connect($olt);
        $config = array_merge($olt->toArray(), ['enable_password' => $olt->enable_password ?? null]);
        $driver = new HuaweiOltDriver($conn, $config);

        try {
            return $this->call($driver, $method, $params);
        } finally {
            try {
                if (method_exists($conn, 'close'))          $conn->close();
                elseif (method_exists($conn, 'disconnect')) $conn->disconnect();
            } catch (\Throwable) {}
        }
    }

    private function call(HuaweiOltDriver $driver, string $method, array $p): mixed
    {
        return match ($method) {
            'getVersion'        => $driver->getVersion(),
            'getUnauthONTs'     => $driver->getUnauthONTs(),
            'getAuthorizedONTs' => $driver->getAuthorizedONTs(),
            'getOntInfo'        => $driver->getOntInfo($p['fsp'], (int) $p['ont_id']),
            'getServicePorts'   => $driver->getServicePorts(
                                       $p['fsp'] ?? null,
                                       isset($p['ont_id']) ? (int) $p['ont_id'] : null
                                   ),
            'registerONT'       => $driver->registerONT($p['fsp'], $p['serial'], $p['description'] ?? $p['serial']),
            'deleteONT'         => $driver->deleteONT($p['fsp'], (int) $p['ont_id'], (array) ($p['service_ports'] ?? [])),
            'assignToClient'    => $driver->assignToClient(
                                       $p['fsp'], (int) $p['ont_id'], (int) $p['vlan'],
                                       (int) $p['service_port'], $p['description'] ?? ''
                                   ),
            'transferONT'       => $driver->transferONT($p['from_fsp'], (int) $p['ont_id'], $p['to_fsp']),
            'deactivateONT'     => $driver->deactivateONT($p['fsp'], (int) $p['ont_id']),
            'activateONT'       => $driver->activateONT($p['fsp'], (int) $p['ont_id']),
            'getLineProfiles'   => $driver->getLineProfiles(),
            'getSrvProfiles'    => $driver->getSrvProfiles(),
            'runCommand'        => $driver->runCommand($p['command']),
            default             => throw new \RuntimeException("Método OLT desconocido: {$method}"),
        };
    }
}
