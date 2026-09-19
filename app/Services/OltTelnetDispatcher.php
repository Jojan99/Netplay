<?php

namespace App\Services;

use App\Models\OltAdmin;
use App\OltDrivers\FabricaDeDrivers;
use App\OltDrivers\Interfaces\OltDriverInterface;
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

        // Hay un worker vivo pero ocupado con un comando largo: su cola sigue
        // funcionando. Arrancar otro, o abrir una conexión directa, le quita a
        // la OLT uno de los pocos cupos de sesión que tiene (fue lo que la dejó
        // sin cupos: "Reenter times").
        if (Redis::exists("olt:{$oltId}:worker_lock")) {
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

        // Los que mueven equipos y esperan a que vuelvan (y revierten si no)
        // tardan más: cortar la espera dejaba al panel diciendo "timeout"
        // mientras el worker seguía cambiando la ONT sin nadie mirando.
        $timeout = in_array($method, ['darGestionAOnt', 'prepararGestionPorPerfil', 'prepararVlanDeGestion'], true)
            ? 240
            : self::RESULT_TIMEOUT;

        $result = Redis::blpop($resultKey, $timeout);

        if ($result === null) {
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
        $driver = FabricaDeDrivers::para($olt, $conn);

        try {
            return $this->call($driver, $method, $params);
        } finally {
            try {
                if (method_exists($conn, 'close'))          $conn->close();
                elseif (method_exists($conn, 'disconnect')) $conn->disconnect();
            } catch (\Throwable) {}
        }
    }

    private function call(OltDriverInterface $driver, string $method, array $p): mixed
    {
        // ZTE: el tipo de ONU elegido en el alta (el modelo real).
        if ($method === 'registerONT' && !empty($p['onu_type']) && method_exists($driver, 'usarTipoOnu')) {
            $driver->usarTipoOnu((string) $p['onu_type']);
        }

        return match ($method) {
            'getVersion'        => $driver->getVersion(),
            'getUnauthONTs'     => $driver->getUnauthONTs(),
            'getAuthorizedONTs' => $driver->getAuthorizedONTs(),
            'getOntInfo'        => $driver->getOntInfo($p['fsp'], (int) $p['ont_id']),
            'getServicePorts'   => $driver->getServicePorts(
                                       $p['fsp'] ?? null,
                                       isset($p['ont_id']) ? (int) $p['ont_id'] : null
                                   ),
            // Se pasaban sólo los tres primeros, así que los perfiles caían
            // siempre a los de la configuración y la VLAN llegaba nula: por eso
            // el service-port no se creaba nunca al autorizar.
            'registerONT'       => $driver->registerONT(
                                       $p['fsp'],
                                       $p['serial'],
                                       $p['description'] ?? $p['serial'],
                                       isset($p['line_profile_id']) ? (int) $p['line_profile_id'] : null,
                                       isset($p['srv_profile_id'])  ? (int) $p['srv_profile_id']  : null,
                                       isset($p['vlan'])            ? (int) $p['vlan']            : null,
                                       isset($p['service_port'])    ? (int) $p['service_port']    : null,
                                   ),
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
            'capacidades'       => method_exists($driver, 'capacidades')
                                       ? $driver->capacidades() : null,
            'pasoVlan'          => method_exists($driver, 'pasoVlan')
                                       ? $driver->pasoVlan($p['fsp'], (int) $p['vlan']) : null,
            // Sólo algunos equipos la tienen (hoy, C-Data EPON).
            'puertosDeSubida'   => method_exists($driver, 'puertosDeSubida')
                                       ? $driver->puertosDeSubida() : [],
            'prepararGestionPorPerfil' => method_exists($driver, 'prepararGestionPorPerfil')
                                       ? $driver->prepararGestionPorPerfil((int) $p['vlan'], (string) $p['url'], $p['usuario'] ?? null, $p['clave'] ?? null) : null,
            'quitarGestionDeOnt' => method_exists($driver, 'quitarGestionDeOnt')
                                       ? $driver->quitarGestionDeOnt((string) $p['fsp'], (int) $p['ont_id'], (int) $p['vlan']) : null,
            'quitarGestionEpon' => method_exists($driver, 'quitarGestionEpon')
                                       ? $driver->quitarGestionEpon((string) $p['fsp'], (int) $p['ont_id']) : null,
            'servidorTr069Vigente' => method_exists($driver, 'servidorTr069Vigente')
                                       ? $driver->servidorTr069Vigente((int) $p['perfil'], (string) $p['url']) : null,
            'prepararVlanDeGestion' => method_exists($driver, 'prepararVlanDeGestion')
                                       ? $driver->prepararVlanDeGestion((int) $p['vlan'], (string) $p['uplink']) : null,
            'darGestionAOnt'    => method_exists($driver, 'darGestionAOnt')
                                       ? $driver->darGestionAOnt($p['fsp'], (int) $p['ont_id'], (int) $p['vlan'], (int) $p['service_port'],
                                           array_map('intval', (array) ($p['vlans_cliente'] ?? [])), (bool) ($p['pisar_ajenas'] ?? false),
                                           (bool) ($p['aunque_tenga_tr069'] ?? false)) : null,
            'perfilDeOnt'       => method_exists($driver, 'perfilDeOnt')
                                       ? $driver->perfilDeOnt((string) $p['fsp'], (int) $p['ont_id']) : null,
            'perfilesDeLinea'   => method_exists($driver, 'perfilesDeLinea')
                                       ? $driver->perfilesDeLinea() : null,
            'perfilDeLinea'     => method_exists($driver, 'perfilDeLinea')
                                       ? $driver->perfilDeLinea((int) $p['perfil']) : null,
            'prepararPerfilDeLinea' => method_exists($driver, 'prepararPerfilDeLinea')
                                       ? $driver->prepararPerfilDeLinea((int) $p['perfil'], (int) $p['vlan']) : null,
            'crearServidorTr069' => method_exists($driver, 'crearServidorTr069')
                                       ? $driver->crearServidorTr069((int) $p['perfil'], (string) $p['nombre'], (string) $p['url'], (string) $p['usuario'], (string) $p['clave']) : null,
            'asignarServidorTr069' => method_exists($driver, 'asignarServidorTr069')
                                       ? $driver->asignarServidorTr069((string) $p['fsp'], (int) $p['ont_id'], (int) $p['perfil'], $p['url'] ?? null, $p['usuario'] ?? null, $p['clave'] ?? null) : null,
            'reiniciarOnt'      => method_exists($driver, 'reiniciarOnt')
                                       ? $driver->reiniciarOnt((string) $p['fsp'], (int) $p['ont_id']) : null,
            'opticaDeOnt'       => method_exists($driver, 'opticaDeOnt')
                                       ? $driver->opticaDeOnt((string) $p['fsp'], (int) $p['ont_id']) : [],
            'potenciasDelPuerto' => method_exists($driver, 'potenciasDelPuerto')
                                       ? $driver->potenciasDelPuerto((string) $p['fsp']) : [],
            'equipoDeOnt'       => method_exists($driver, 'equipoDeOnt')
                                       ? $driver->equipoDeOnt($p['fsp'], (int) $p['ont_id']) : [],
            'autoAutorizacion'  => method_exists($driver, 'autoAutorizacion')
                                       ? $driver->autoAutorizacion() : null,
            'cambiarAutoAutorizacion' => method_exists($driver, 'cambiarAutoAutorizacion')
                                       ? $driver->cambiarAutoAutorizacion((bool) ($p['activar'] ?? false), isset($p['puerto']) ? (int) $p['puerto'] : null) : null,
            default             => throw new \RuntimeException("Método OLT desconocido: {$method}"),
        };
    }
}
