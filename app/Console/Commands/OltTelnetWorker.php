<?php

namespace App\Console\Commands;

use App\Models\OltAdmin;
use App\OltDrivers\FabricaDeDrivers;
use App\OltDrivers\Interfaces\OltDriverInterface;
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

    /**
     * Lo máximo que puede tardar un comando antes de darlo por colgado. Pasado
     * ese tiempo se corta y se cierra la sesión: la OLT sólo admite unas pocas
     * y una sesión colgada las va agotando.
     */
    private const LIMITE_COMANDO = 200;
    // Más largo que el comando más lento: con 30 s, un comando de un minuto
    // dejaba vencer el lock y arrancaba un segundo worker con su propia
    // sesión, hasta agotar los cupos de la OLT.
    private const LOCK_TTL = 300;      // El lock se renueva en cada vuelta

    /** Con esta llave este proceso reclama ser el único worker de la OLT. */
    private ?string $lockKey = null;
    private const BLPOP_TIMEOUT = 5;   // segundos que espera por un comando antes de re-loop
    private const SAVE_DELAY    = 30;  // segundos de inactividad tras escritura antes de auto-save

    private const WRITE_METHODS = [
        'registerONT', 'deleteONT', 'assignToClient',
        'transferONT', 'deactivateONT', 'activateONT',
        // Cambia la configuración: si no se guarda, vuelve atrás al reiniciar.
        'cambiarAutoAutorizacion', 'prepararVlanDeGestion', 'darGestionAOnt',
        'prepararPerfilDeLinea', 'crearServidorTr069', 'asignarServidorTr069',
        'prepararGestionPorPerfil', 'quitarGestionEpon', 'quitarGestionDeOnt',
    ];

    private ?object          $connection     = null;
    private ?OltDriverInterface $driver         = null;
    private float            $lastActivityAt = 0.0;
    private bool             $pendingSave    = false;
    private float            $lastWriteAt    = 0.0;

    public function handle(OltConnectionFactory $factory): int
    {
        $oltId    = (int) $this->argument('olt_id');
        $queueKey = "olt:{$oltId}:cmd_queue";

        // Un solo worker por OLT. Cada uno abre su propia sesión telnet, y la
        // OLT admite unas pocas a la vez: con dos corriendo se agotaban los
        // cupos y cualquier consulta moría con "Reenter times reached upper
        // limit". El lock del dispatcher sólo cubre el arranque, así que si el
        // worker anterior seguía vivo igual se lanzaba otro.
        $lockKey = "olt:{$oltId}:worker_lock";

        if (!Redis::set($lockKey, getmypid(), 'EX', self::LOCK_TTL, 'NX')) {
            $this->warn("OLT Worker #{$oltId}: ya hay otro worker atendiendo esta OLT, este proceso termina.");
            Log::info("OLT Worker #{$oltId}: no arranca, ya hay otro vivo");

            return self::SUCCESS;
        }

        $this->lockKey = $lockKey;

        // Si al proceso lo matan, el lock se suelta enseguida en vez de
        // esperar a que venza y dejar la OLT sin worker todo ese rato.
        register_shutdown_function(fn () => $this->soltarLock());

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);

            foreach ([SIGTERM, SIGINT, SIGHUP] as $senal) {
                pcntl_signal($senal, function () use ($oltId) {
                    $this->disconnect($oltId);
                    $this->soltarLock();
                    exit(0);
                });
            }
        }

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

            if (!$this->renovarLock()) {
                Log::warning("OLT Worker #{$oltId}: otro worker tomó la OLT, este termina para no ocupar otra sesión");
                $this->disconnect($oltId);
                // El lock es del otro: al salir no hay que soltarlo.
                $this->lockKey = null;

                return self::SUCCESS;
            }

            // El worker vive indefinidamente, así que se queda con el código
            // que tenía al arrancar: después de un despliegue sigue corriendo
            // el viejo hasta que alguien lo mata. Con esta señal termina solo
            // y el dispatcher lo vuelve a levantar ya actualizado.
            if (Redis::get("olt:{$oltId}:recargar")) {
                Redis::del("olt:{$oltId}:recargar");
                $this->info("OLT #{$oltId}: recarga pedida, el worker termina para volver con el código nuevo.");
                Log::info("OLT Worker #{$oltId}: termina por recarga");
                $this->disconnect($oltId);
                $this->soltarLock();

                return self::SUCCESS;
            }
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

                // Mientras dura el comando no se puede publicar el latido: si
                // uno se colgaba, el latido vencía a los 15 s, el dispatcher
                // creía que no había worker y arrancaba otro, que abría otra
                // sesión. Con dos o tres así la OLT se queda sin cupos. Se
                // alarga el latido a lo que puede tardar el comando y se corta
                // por tiempo, para soltar la sesión en vez de quedar colgado.
                Redis::setex("olt:{$oltId}:worker_alive", self::LIMITE_COMANDO + 30, '1');
                $this->armarCorte($oltId, $command['method']);

                $data = $this->executeCommand($command);
                $this->desarmarCorte();
                Redis::rpush($resultKey, json_encode(['success' => true, 'data' => $data]));

                // Si la lectura terminó sin llegar al prompt, la sesión quedó
                // con datos sin consumir y el próximo comando los leería como
                // su propia respuesta. Se cierra para volver con una limpia.
                if (method_exists($this->driver, 'estaDesincronizada') && $this->driver->estaDesincronizada()) {
                    Log::warning("OLT Worker #{$oltId}: sesión desincronizada tras {$command['method']}, se reabrirá");
                    $this->disconnect($oltId);
                }

                if (in_array($command['method'], self::WRITE_METHODS, true)) {
                    $this->pendingSave = true;
                    $this->lastWriteAt = microtime(true);
                }
            } catch (\Throwable $e) {
                $this->desarmarCorte();
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

        // Si la OLT rechaza la sesión (sin cupos, o pidiendo usuario otra vez),
        // el driver lanza en su constructor. Sin cerrar acá, el socket quedaba
        // abierto ocupando uno de los pocos cupos que tiene el equipo.
        try {
            $this->driver = FabricaDeDrivers::para($olt, $this->connection);
        } catch (\Throwable $e) {
            $this->disconnect($oltId);

            throw $e;
        }

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

    /**
     * Corta un comando que se quedó colgado (la OLT dejó de responder, o pidió
     * algo que nadie contesta). Sin esto el worker se quedaba esperando para
     * siempre con la sesión tomada.
     */
    private function armarCorte(int $oltId, string $metodo): void
    {
        if (!function_exists('pcntl_alarm')) {
            return;
        }

        pcntl_signal(SIGALRM, function () use ($oltId, $metodo) {
            Log::warning("OLT Worker #{$oltId}: {$metodo} pasó de " . self::LIMITE_COMANDO . 's, se corta y se cierra la sesión');

            throw new \RuntimeException('La OLT no respondió a tiempo: se cerró la sesión para no dejarla tomada.');
        });

        pcntl_alarm(self::LIMITE_COMANDO);
    }

    private function desarmarCorte(): void
    {
        if (function_exists('pcntl_alarm')) {
            pcntl_alarm(0);
        }
    }

    /** Mantiene el lock mientras este worker sigue vivo. */
    /**
     * Mantiene el lock mientras este worker sigue siendo el dueño.
     *
     * Si otro proceso lo tomó (este tardó más que el vencimiento y arrancó
     * otro worker), devuelve false: renovarlo igual dejaba a los dos creyéndose
     * dueños, cada uno con su sesión, hasta agotar los cupos de la OLT.
     */
    private function renovarLock(): bool
    {
        if (!$this->lockKey) {
            return true;
        }

        try {
            $duenio = Redis::get($this->lockKey);

            if ($duenio !== null && (int) $duenio !== getmypid()) {
                return false;
            }

            Redis::setex($this->lockKey, self::LOCK_TTL, getmypid());
        } catch (\Throwable) {
        }

        return true;
    }

    /**
     * Suelta el lock al terminar.
     *
     * Si el proceso muere de golpe el lock vence solo por TTL, así que la OLT
     * no queda bloqueada para siempre.
     */
    private function soltarLock(): void
    {
        if ($this->lockKey) {
            // Sólo si sigue siendo nuestro: borrar el de otro worker lo dejaba
            // sin candado y abría la puerta a un tercero.
            try {
                if ((int) Redis::get($this->lockKey) === getmypid()) {
                    Redis::del($this->lockKey);
                }
            } catch (\Throwable) {
            }

            $this->lockKey = null;
        }
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

        // ZTE: el tipo de ONU elegido en el alta (el modelo real).
        if ($method === 'registerONT' && !empty($p['onu_type']) && method_exists($this->driver, 'usarTipoOnu')) {
            $this->driver->usarTipoOnu((string) $p['onu_type']);
        }

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
            'capacidades'       => method_exists($this->driver, 'capacidades')
                                       ? $this->driver->capacidades() : null,
            'pasoVlan'          => method_exists($this->driver, 'pasoVlan')
                                       ? $this->driver->pasoVlan($p['fsp'], (int) $p['vlan']) : null,
            // Sólo algunos equipos la tienen (hoy, C-Data EPON).
            'puertosDeSubida'   => method_exists($this->driver, 'puertosDeSubida')
                                       ? $this->driver->puertosDeSubida() : [],
            'prepararGestionPorPerfil' => method_exists($this->driver, 'prepararGestionPorPerfil')
                                       ? $this->driver->prepararGestionPorPerfil((int) $p['vlan'], (string) $p['url'], $p['usuario'] ?? null, $p['clave'] ?? null) : null,
            'quitarGestionDeOnt' => method_exists($this->driver, 'quitarGestionDeOnt')
                                       ? $this->driver->quitarGestionDeOnt((string) $p['fsp'], (int) $p['ont_id'], (int) $p['vlan']) : null,
            'quitarGestionEpon' => method_exists($this->driver, 'quitarGestionEpon')
                                       ? $this->driver->quitarGestionEpon((string) $p['fsp'], (int) $p['ont_id']) : null,
            'servidorTr069Vigente' => method_exists($this->driver, 'servidorTr069Vigente')
                                       ? $this->driver->servidorTr069Vigente((int) $p['perfil'], (string) $p['url']) : null,
            'prepararVlanDeGestion' => method_exists($this->driver, 'prepararVlanDeGestion')
                                       ? $this->driver->prepararVlanDeGestion((int) $p['vlan'], (string) $p['uplink']) : null,
            'darGestionAOnt'    => method_exists($this->driver, 'darGestionAOnt')
                                       ? $this->driver->darGestionAOnt($p['fsp'], (int) $p['ont_id'], (int) $p['vlan'], (int) $p['service_port'],
                                           // Igual que en OltTelnetDispatcher (conexión directa): sin esto
                                           // la opción de pisar conexiones ajenas no llegaba al driver.
                                           array_map('intval', (array) ($p['vlans_cliente'] ?? [])), (bool) ($p['pisar_ajenas'] ?? false),
                                           (bool) ($p['aunque_tenga_tr069'] ?? false)) : null,
            'perfilDeOnt'       => method_exists($this->driver, 'perfilDeOnt')
                                       ? $this->driver->perfilDeOnt((string) $p['fsp'], (int) $p['ont_id']) : null,
            'perfilesDeLinea'   => method_exists($this->driver, 'perfilesDeLinea')
                                       ? $this->driver->perfilesDeLinea() : null,
            'perfilDeLinea'     => method_exists($this->driver, 'perfilDeLinea')
                                       ? $this->driver->perfilDeLinea((int) $p['perfil']) : null,
            'prepararPerfilDeLinea' => method_exists($this->driver, 'prepararPerfilDeLinea')
                                       ? $this->driver->prepararPerfilDeLinea((int) $p['perfil'], (int) $p['vlan']) : null,
            'crearServidorTr069' => method_exists($this->driver, 'crearServidorTr069')
                                       ? $this->driver->crearServidorTr069((int) $p['perfil'], (string) $p['nombre'], (string) $p['url'], (string) $p['usuario'], (string) $p['clave']) : null,
            'asignarServidorTr069' => method_exists($this->driver, 'asignarServidorTr069')
                                       ? $this->driver->asignarServidorTr069((string) $p['fsp'], (int) $p['ont_id'], (int) $p['perfil'], $p['url'] ?? null, $p['usuario'] ?? null, $p['clave'] ?? null) : null,
            'reiniciarOnt'      => method_exists($this->driver, 'reiniciarOnt')
                                       ? $this->driver->reiniciarOnt((string) $p['fsp'], (int) $p['ont_id']) : null,
            'opticaDeOnt'       => method_exists($this->driver, 'opticaDeOnt')
                                       ? $this->driver->opticaDeOnt((string) $p['fsp'], (int) $p['ont_id']) : [],
            'potenciasDelPuerto' => method_exists($this->driver, 'potenciasDelPuerto')
                                       ? $this->driver->potenciasDelPuerto((string) $p['fsp']) : [],
            'equipoDeOnt'       => method_exists($this->driver, 'equipoDeOnt')
                                       ? $this->driver->equipoDeOnt($p['fsp'], (int) $p['ont_id']) : [],
            'autoAutorizacion'  => method_exists($this->driver, 'autoAutorizacion')
                                       ? $this->driver->autoAutorizacion() : null,
            'cambiarAutoAutorizacion' => method_exists($this->driver, 'cambiarAutoAutorizacion')
                                       ? $this->driver->cambiarAutoAutorizacion((bool) ($p['activar'] ?? false), isset($p['puerto']) ? (int) $p['puerto'] : null) : null,
            default             => throw new \RuntimeException("Método OLT desconocido: {$method}"),
        };
    }
}
