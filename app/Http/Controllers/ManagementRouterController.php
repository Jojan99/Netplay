<?php

namespace App\Http\Controllers;

use App\Constants\ApiResponseConstants;
use App\Models\OltAdmin;
use App\Services\HuaweiSnmpReader;
use App\Http\Requests\Gestions\GestionUserRequest;
use App\Http\Requests\Gestions\OltDataRequest;
use App\Managers\Interfaces\SSHConnectionManagerInterface;
use App\Helpers\RouterHostParser;
use App\Services\SSHConnectionService;
use App\UseCases\ManagementRouter\Interfaces\GetIpAvaliblesUseCaseInterface;
use App\UseCases\ManagementRouter\Interfaces\MikrotikInfoUseCaseInterface;
use App\UseCases\ManagementRouter\Interfaces\UpdateStatusUserUseCaseInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use phpseclib3\Exception\ConnectionClosedException;
use phpseclib3\Net\SSH2;
use SSHConnectionManager;
use Tymon\JWTAuth\Exceptions\JWTException;

class ManagementRouterController extends Controller
{
    // ── Router CRUD ───────────────────────────────────────────────────────────

    public function listRouters(MikrotikInfoUseCaseInterface $uc): object
    {
        $result = $uc->listRouters();
        return standardApiReponse($result['message'], $result['data'], $result['status'], JsonResponse::HTTP_OK);
    }

    public function storeRouter(Request $request, MikrotikInfoUseCaseInterface $uc): object
    {
        $data = $request->only(['name', 'host', 'user', 'pass', 'port']);
        if (empty($data['host']) || empty($data['user']) || empty($data['pass'])) {
            return standardApiReponse('host, user y pass son requeridos', null, 1, JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }
        $parsed = RouterHostParser::parse($data['host'], $data['port'] ?? null);
        $data['host'] = $parsed['host'];
        $data['port'] = $parsed['port'];
        $result = $uc->createRouter($data);
        return standardApiReponse($result['message'], $result['data'], $result['status'], JsonResponse::HTTP_OK);
    }

    public function updateRouterById(Request $request, int $id, MikrotikInfoUseCaseInterface $uc): object
    {
        $data   = $request->only(['name', 'host', 'user', 'pass', 'port']);
        if (!empty($data['host'])) {
            $parsed = RouterHostParser::parse($data['host'], $data['port'] ?? null);
            $data['host'] = $parsed['host'];
            $data['port'] = $parsed['port'];
        }
        $result = $uc->updateRouter($id, $data);
        return standardApiReponse($result['message'], $result['data'], $result['status'], JsonResponse::HTTP_OK);
    }

    public function destroyRouter(int $id, MikrotikInfoUseCaseInterface $uc): object
    {
        $result = $uc->deleteRouter($id);
        return standardApiReponse($result['message'], $result['data'], $result['status'], JsonResponse::HTTP_OK);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function routerId(Request $request): ?int
    {
        $id = $request->input('router_id');
        return $id ? (int) $id : null;
    }

    // ── IP / LAN ──────────────────────────────────────────────────────────────

    public function getLanSegments(
        GetIpAvaliblesUseCaseInterface $getIpAvaliblesUseCaseInterface,
        Request $request
    ): object {
        try {
            $result = $getIpAvaliblesUseCaseInterface->getLanSegments($this->routerId($request), $request->boolean('todas'));
        } catch (JWTException $e) {
            return standardApiReponse('Error: ' . $e->getMessage(), ApiResponseConstants::DATA_NULL, ApiResponseConstants::ERROR, JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }

        return standardApiReponse($result['message'], $result['data'], $result['status'], JsonResponse::HTTP_OK);
    }

    /**
     * Clientes que están compartiendo una misma IP.
     *
     * No toca el router: es una lectura de lo que la plataforma tiene
     * registrado, para poder ir resolviéndolos de a uno con la migración de
     * IP que ya existe.
     */
    public function ipConflicts(\App\Managers\Interfaces\ConectionRouterManagerInterface $conexion): object
    {
        $companyId = getSessionCompanyId();

        if (!$companyId) {
            return standardApiReponse('Sesión sin empresa asociada', null, 1, JsonResponse::HTTP_UNAUTHORIZED);
        }

        // Se le pasa el ARP para poder distinguir el conflicto de verdad —dos
        // clientes con la misma IP en el router— del que es sólo el dato mal
        // en la plataforma. Si el router no responde igual se lista, con el
        // criterio viejo, que marca de más.
        $arp = \App\Services\Red\ArpDelRouter::documentos($conexion, $companyId);

        $datos = (new \App\Services\Red\ConflictosDeIp($companyId))->listar($arp);

        return standardApiReponse('Conflictos de IP', $datos, 0, JsonResponse::HTTP_OK);
    }

    /**
     * Le da a cada cliente su propio registro de IP, con la que tiene en el router.
     *
     * No toca el MikroTik: los clientes que comparten registro casi nunca
     * comparten la IP de verdad, así que separarlos no le corta el servicio a
     * nadie. Con simular=true sólo informa qué haría.
     */
    public function separarFichas(Request $request, \App\Managers\Interfaces\ConectionRouterManagerInterface $conexion): object
    {
        $companyId = getSessionCompanyId();

        if (!$companyId) {
            return standardApiReponse('Sesión sin empresa asociada', null, 1, JsonResponse::HTTP_UNAUTHORIZED);
        }

        $datos = (new \App\Services\Red\SepararFichasCompartidas($conexion, $companyId))
            ->ejecutar($request->boolean('simular'));

        $huboError = $datos['errores'] !== [];

        return standardApiReponse(
            $huboError ? implode(' ', $datos['errores']) : 'Registros separados',
            $datos,
            $huboError ? 1 : 0,
            JsonResponse::HTTP_OK
        );
    }

    /**
     * Trae al sistema la IP que cada cliente tiene de verdad en el MikroTik.
     *
     * Con simular=true no escribe nada: devuelve qué cambiaría, que es como
     * conviene mirarlo antes de aplicarlo.
     */
    public function syncIps(Request $request, \App\Managers\Interfaces\ConectionRouterManagerInterface $conexion): object
    {
        $companyId = getSessionCompanyId();

        if (!$companyId) {
            return standardApiReponse('Sesión sin empresa asociada', null, 1, JsonResponse::HTTP_UNAUTHORIZED);
        }

        $simular  = $request->boolean('simular');
        $routerId = $request->input('router_id') ? (int) $request->input('router_id') : null;

        $datos = (new \App\Services\Red\SincronizarIpsDesdeRouter($conexion, $companyId))
            ->ejecutar($simular, $routerId);

        $huboError = $datos['errores'] !== [] && $datos['cambios'] === [];

        return standardApiReponse(
            $huboError ? implode(' ', $datos['errores']) : 'Sincronización lista',
            $datos,
            $huboError ? 1 : 0,
            JsonResponse::HTTP_OK
        );
    }

    /**
     * Foto del equipo que corresponde al modelo del router.
     *
     * Va aparte de getRouterInfo porque la primera vez sale a buscarla y no
     * conviene demorar la pantalla entera por una imagen; después queda
     * guardada y responde al instante.
     */
    public function routerPhoto(Request $request): object
    {
        $datos = \App\Services\Red\FotoDelRouter::resolver($request->query('board'));

        return standardApiReponse('Foto del equipo', $datos, 0, JsonResponse::HTTP_OK);
    }

    /**
     * Todo lo que el router sabe de un puerto: enlace, SFP, tráfico en vivo,
     * VLAN, IP y los clientes que cuelgan de ahí.
     */
    public function portDetail(
        Request $request,
        \App\Managers\Interfaces\ConectionRouterManagerInterface $conexion,
        MikrotikInfoUseCaseInterface $uc
    ): object {
        $puerto = trim((string) $request->query('name'));

        if ($puerto === '') {
            return standardApiReponse('Falta el nombre del puerto', null, 1, JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $companyId = getSessionCompanyId();

        if (!$companyId) {
            return standardApiReponse('Sesión sin empresa asociada', null, 1, JsonResponse::HTTP_UNAUTHORIZED);
        }

        $routerId = $request->query('router_id') ? (int) $request->query('router_id') : null;

        $router = \Illuminate\Support\Facades\DB::table('conection_routers')
            ->where('company_id', $companyId)
            ->when($routerId, fn ($q) => $q->where('id', $routerId))
            ->first(['token']);

        if (!$router) {
            return standardApiReponse('No hay router configurado', null, 1, JsonResponse::HTTP_OK);
        }

        try {
            $datos = (new \App\Services\Red\DetalleDePuerto($conexion, $router->token))->de($puerto);
        } catch (\Throwable $e) {
            return standardApiReponse('No se pudo consultar el puerto: ' . $e->getMessage(), null, 1, JsonResponse::HTTP_OK);
        }

        return standardApiReponse('Detalle del puerto', $datos, 0, JsonResponse::HTTP_OK);
    }

    /** Qué tiene el router preparado para PPPoE y quién está conectado. */
    public function pppoeEstado(Request $request, \App\Managers\Interfaces\ConectionRouterManagerInterface $conexion): object
    {
        $token = $this->tokenDelRouter($request);

        if (!$token) {
            return standardApiReponse('No hay router configurado', null, 1, JsonResponse::HTTP_OK);
        }

        try {
            $servicio = new \App\Services\Red\ServicioPppoe($conexion, $token);

            return standardApiReponse('Estado de PPPoE', [
                'estado'     => $servicio->estado(),
                'usuarios'   => $servicio->usuarios(),
                'pools'      => $servicio->pools(),
                'interfaces' => $servicio->interfaces(),
            ], 0, JsonResponse::HTTP_OK);
        } catch (\Throwable $e) {
            return standardApiReponse('No se pudo consultar el router: ' . $e->getMessage(), null, 1, JsonResponse::HTTP_OK);
        }
    }

    /** Pasa un cliente de IP fija a PPPoE o al revés. */
    public function cambiarConexion(Request $request, \App\Managers\Interfaces\ConectionRouterManagerInterface $conexion): object
    {
        $companyId = getSessionCompanyId();

        if (!$companyId) {
            return standardApiReponse('Sesión sin empresa asociada', null, 1, JsonResponse::HTTP_UNAUTHORIZED);
        }

        $userId = (int) $request->input('user_id');

        if (!$userId) {
            return standardApiReponse('Falta el cliente', null, 1, JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $r = (new \App\Services\Red\CambiarConexionCliente($conexion, $companyId))->aplicar(
            $userId,
            $request->only(['connection_type', 'pppoe_user', 'pppoe_password', 'pppoe_profile', 'ip', 'vlan'])
        );

        // Con la ONT en el TR-069, el equipo se reconfigura solo: sin esto había
        // que ir al equipo o reautorizarlo para que tomara la conexión nueva.
        $aprovisionamiento = null;

        if ($r['ok']) {
            try {
                $extra = \App\Services\Red\AprovisionamientoDeOnt::reaplicarConexion((int) $companyId, $userId);
                if ($extra) {
                    $r['mensaje'] = preg_replace('/ Tiene que (reconectar|reiniciar)[^.]*\./', '', $r['mensaje']) . ' ' . $extra['texto'];
                    $aprovisionamiento = $extra['id'];
                }
            } catch (\Throwable $e) {
                \Log::warning('[Aprovisionamiento] No se pudo programar tras cambiar la conexión', ['user' => $userId, 'error' => $e->getMessage()]);
            }
        }

        return standardApiReponse($r['mensaje'], ['aprovisionamiento' => $aprovisionamiento], $r['ok'] ? 0 : 1, JsonResponse::HTTP_OK);
    }

    /** Qué hace falta decidir para montar el servidor PPPoE. */
    public function pppoeOpciones(Request $request, \App\Managers\Interfaces\ConectionRouterManagerInterface $conexion): object
    {
        $token = $this->tokenDelRouter($request);

        if (!$token) {
            return standardApiReponse('No hay router configurado', null, 1, JsonResponse::HTTP_OK);
        }

        try {
            $datos = (new \App\Services\Red\ConfigurarServidorPppoe($conexion, $token))->opciones();

            return standardApiReponse('Opciones para PPPoE', $datos, 0, JsonResponse::HTTP_OK);
        } catch (\Throwable $e) {
            return standardApiReponse('No se pudo leer el router: ' . $e->getMessage(), null, 1, JsonResponse::HTTP_OK);
        }
    }

    /** Monta el servidor PPPoE en el router: pool, perfil y servicio. */
    public function pppoeMontar(Request $request, \App\Managers\Interfaces\ConectionRouterManagerInterface $conexion): object
    {
        $token = $this->tokenDelRouter($request);

        if (!$token) {
            return standardApiReponse('No hay router configurado', null, 1, JsonResponse::HTTP_OK);
        }

        $r = (new \App\Services\Red\ConfigurarServidorPppoe($conexion, $token))->montar(
            $request->only(['interfaz', 'pool', 'rango', 'gateway', 'perfil', 'servicio', 'perfiles', 'salida'])
        );

        return standardApiReponse(
            $r['ok'] ? 'Servidor PPPoE configurado' : ($r['error'] ?? 'No se pudo configurar'),
            $r,
            $r['ok'] ? 0 : 1,
            JsonResponse::HTTP_OK
        );
    }

    /** Qué VLAN ya atienden PPPoE y qué se crearía en las que faltan. */
    public function pppoePropuesta(Request $request, \App\Managers\Interfaces\ConectionRouterManagerInterface $conexion): object
    {
        $token = $this->tokenDelRouter($request);

        if (!$token) {
            return standardApiReponse('No hay router configurado', null, 1, JsonResponse::HTTP_OK);
        }

        try {
            $datos = (new \App\Services\Red\ConfigurarServidorPppoe($conexion, $token))->propuestaPorVlan();

            return standardApiReponse('Propuesta de PPPoE por VLAN', $datos, 0, JsonResponse::HTTP_OK);
        } catch (\Throwable $e) {
            return standardApiReponse('No se pudo leer el router: ' . $e->getMessage(), null, 1, JsonResponse::HTTP_OK);
        }
    }

    /** Crea el PPPoE de las VLAN elegidas, cada una con su rango. */
    public function pppoeAutomatico(Request $request, \App\Managers\Interfaces\ConectionRouterManagerInterface $conexion): object
    {
        $request->validate([
            'interfaces'   => 'array',
            'interfaces.*' => 'string|max:64',
            'corregir'     => 'array',
            'corregir.*'   => 'string|max:64',
        ]);

        $token = $this->tokenDelRouter($request);

        if (!$token) {
            return standardApiReponse('No hay router configurado', null, 1, JsonResponse::HTTP_OK);
        }

        try {
            $r = (new \App\Services\Red\ConfigurarServidorPppoe($conexion, $token))
                ->montarPorVlan((array) $request->input('interfaces', []), (array) $request->input('corregir', []));

            return standardApiReponse($r['ok'] ? 'PPPoE listo' : 'Quedaron cosas sin hacer', $r, $r['ok'] ? 0 : 1, JsonResponse::HTTP_OK);
        } catch (\Throwable $e) {
            return standardApiReponse('No se pudo leer el router: ' . $e->getMessage(), null, 1, JsonResponse::HTTP_OK);
        }
    }

    /** Si lo elegido en el paso a paso choca con lo que ya hay en el router. */
    public function pppoeValidar(Request $request, \App\Managers\Interfaces\ConectionRouterManagerInterface $conexion): object
    {
        $token = $this->tokenDelRouter($request);

        if (!$token) {
            return standardApiReponse('No hay router configurado', null, 1, JsonResponse::HTTP_OK);
        }

        try {
            $choques = (new \App\Services\Red\ConfigurarServidorPppoe($conexion, $token))->choques(
                (string) $request->input('interfaz', ''), trim((string) $request->input('pool', '')),
                trim((string) $request->input('rango', '')), trim((string) $request->input('gateway', '')),
                trim((string) $request->input('perfil', '')), trim((string) $request->input('servicio', '')),
            );

            return standardApiReponse($choques ? 'Hay choques' : 'Libre', ['choques' => $choques], 0, JsonResponse::HTTP_OK);
        } catch (\Throwable $e) {
            return standardApiReponse('No se pudo leer el router: ' . $e->getMessage(), null, 1, JsonResponse::HTTP_OK);
        }
    }

    /** Qué se llevaría por delante desmontar PPPoE. */
    public function pppoeQueSeBorra(Request $request, \App\Managers\Interfaces\ConectionRouterManagerInterface $conexion): object
    {
        $token = $this->tokenDelRouter($request);

        if (!$token) {
            return standardApiReponse('No hay router configurado', null, 1, JsonResponse::HTTP_OK);
        }

        try {
            $datos = (new \App\Services\Red\ConfigurarServidorPppoe($conexion, $token))
                ->queSeBorra((string) ($request->query('pool') ?: 'pool-pppoe'));

            return standardApiReponse('Qué se borra', $datos, 0, JsonResponse::HTTP_OK);
        } catch (\Throwable $e) {
            return standardApiReponse('No se pudo leer el router: ' . $e->getMessage(), null, 1, JsonResponse::HTTP_OK);
        }
    }

    /** Desmonta PPPoE del router. */
    public function pppoeDesmontar(Request $request, \App\Managers\Interfaces\ConectionRouterManagerInterface $conexion): object
    {
        $token = $this->tokenDelRouter($request);

        if (!$token) {
            return standardApiReponse('No hay router configurado', null, 1, JsonResponse::HTTP_OK);
        }

        $r = (new \App\Services\Red\ConfigurarServidorPppoe($conexion, $token))->desmontar([
            'usuarios'    => $request->boolean('usuarios'),
            'perfiles'    => $request->boolean('perfiles'),
            'pool'        => $request->boolean('pool'),
            'nombre_pool' => $request->input('nombre_pool', 'pool-pppoe'),
        ]);

        return standardApiReponse(
            $r['ok'] ? 'PPPoE desmontado' : ($r['error'] ?? 'No se pudo desmontar'),
            $r,
            $r['ok'] ? 0 : 1,
            JsonResponse::HTTP_OK
        );
    }

    /**
     * Crear o editar perfiles, rangos y servidores desde el panel.
     *
     * Van juntos en un método porque son la misma operación sobre piezas
     * distintas de PPP, y así la pantalla usa una sola ruta para todo.
     */
    public function pppoeGuardar(Request $request, \App\Managers\Interfaces\ConectionRouterManagerInterface $conexion): object
    {
        $token = $this->tokenDelRouter($request);

        if (!$token) {
            return standardApiReponse('No hay router configurado', null, 1, JsonResponse::HTTP_OK);
        }

        $que = (string) $request->input('que');

        try {
            $servicio = new \App\Services\Red\ServicioPppoe($conexion, $token);

            $extra = match ($que) {
                'perfil'    => $servicio->guardarPerfil($request->all()),
                'pool'      => $servicio->guardarPool($request->all()),
                'servidor'  => $servicio->guardarServidor($request->all()),
                default     => throw new \InvalidArgumentException('No sé qué guardar.'),
            };

            // Al guardar un rango se cuenta también qué pasó con el túnel VPN.
            $mensaje = is_string($extra) && $extra !== '' ? "Guardado · {$extra}" : 'Guardado';

            return standardApiReponse($mensaje, null, 0, JsonResponse::HTTP_OK);
        } catch (\InvalidArgumentException $e) {
            return standardApiReponse($e->getMessage(), null, 1, JsonResponse::HTTP_OK);
        } catch (\Throwable $e) {
            return standardApiReponse('El router rechazó el cambio: ' . $e->getMessage(), null, 1, JsonResponse::HTTP_OK);
        }
    }

    /** Borra un perfil, un rango o un servidor, si no está en uso. */
    public function pppoeEliminar(Request $request, \App\Managers\Interfaces\ConectionRouterManagerInterface $conexion): object
    {
        $token = $this->tokenDelRouter($request);

        if (!$token) {
            return standardApiReponse('No hay router configurado', null, 1, JsonResponse::HTTP_OK);
        }

        $que    = (string) $request->input('que');
        $nombre = trim((string) $request->input('nombre'));

        if ($nombre === '') {
            return standardApiReponse('Falta el nombre', null, 1, JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $servicio = new \App\Services\Red\ServicioPppoe($conexion, $token);

            $r = match ($que) {
                'perfil'   => $servicio->eliminarPerfil($nombre),
                'pool'     => $servicio->eliminarPool($nombre),
                'servidor' => tap(['ok' => true], fn () => $servicio->eliminarServidor($nombre)),
                'usuario'  => tap(['ok' => true], fn () => $servicio->eliminar($nombre)),
                default    => ['ok' => false, 'motivo' => 'No sé qué borrar.'],
            };

            return standardApiReponse(
                $r['ok'] ? 'Eliminado' : ($r['motivo'] ?? 'No se pudo eliminar'),
                null,
                $r['ok'] ? 0 : 1,
                JsonResponse::HTTP_OK
            );
        } catch (\Throwable $e) {
            return standardApiReponse('El router rechazó el cambio: ' . $e->getMessage(), null, 1, JsonResponse::HTTP_OK);
        }
    }

    /** El token del router elegido, o el de la empresa si no vino ninguno. */
    private function tokenDelRouter(Request $request): ?string
    {
        $companyId = getSessionCompanyId();

        if (!$companyId) {
            return null;
        }

        $routerId = $request->input('router_id') ? (int) $request->input('router_id') : null;

        return \Illuminate\Support\Facades\DB::table('conection_routers')
            ->where('company_id', $companyId)
            ->when($routerId, fn ($q) => $q->where('id', $routerId))
            ->value('token');
    }

    public function getIpAvalibles(
        GetIpAvaliblesUseCaseInterface $getIpAvaliblesUseCaseInterface,
        GestionUserRequest $gestionUserRequest
    ): object {
        try {
            $result = $getIpAvaliblesUseCaseInterface->GetIpAvalibles($gestionUserRequest, $this->routerId($gestionUserRequest));
        } catch (JWTException $e) {
            return standardApiReponse('Error: ' . $e->getMessage(), ApiResponseConstants::DATA_NULL, ApiResponseConstants::ERROR, JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }

        return standardApiReponse($result['message'], $result['data'], $result['status'], JsonResponse::HTTP_OK);
    }

    public function autorizarServicio(
        GetIpAvaliblesUseCaseInterface $getIpAvaliblesUseCaseInterface,
        GestionUserRequest $gestionUserRequest
    ): object {
        try {
            $result = $getIpAvaliblesUseCaseInterface->autorizarServicio($gestionUserRequest, $this->routerId($gestionUserRequest));
        } catch (JWTException $e) {
            return standardApiReponse('Error: ' . $e->getMessage(), ApiResponseConstants::DATA_NULL, ApiResponseConstants::ERROR, JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }

        return standardApiReponse($result['message'], $result['data'], $result['status'], JsonResponse::HTTP_OK);
    }

    public function migrarIp(
        GetIpAvaliblesUseCaseInterface $getIpAvaliblesUseCaseInterface,
        GestionUserRequest $gestionUserRequest
    ): object {
        try {
            $result = $getIpAvaliblesUseCaseInterface->migrarIp($gestionUserRequest, $this->routerId($gestionUserRequest));

            // La ONT toma la IP nueva sola si está en el TR-069.
            if (($result['status'] ?? 1) === 0) {
                try {
                    $extra = \App\Services\Red\AprovisionamientoDeOnt::reaplicarConexion((int) getSessionCompanyId(), (int) $gestionUserRequest['service_id']);
                    if ($extra) {
                        $result['message'] .= '. ' . $extra['texto'];
                        $result['data'] = (array) ($result['data'] ?? []) + ['aprovisionamiento' => $extra['id']];
                    }
                } catch (\Throwable $e) {
                    \Log::warning('[Aprovisionamiento] No se pudo programar tras migrar la IP', ['error' => $e->getMessage()]);
                }
            }
        } catch (JWTException $e) {
            return standardApiReponse(
                'Error al migrar IP: ' . $e->getMessage(),
                ApiResponseConstants::DATA_NULL,
                ApiResponseConstants::ERROR,
                JsonResponse::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        return standardApiReponse(
            $result['message'],
            $result['data'],
            $result['status'],
            JsonResponse::HTTP_OK
        );
    }

    public function getRouterConfig(Request $request, MikrotikInfoUseCaseInterface $uc): object
    {
        $result = $uc->getRouterConfig($this->routerId($request));
        return standardApiReponse($result['message'], $result['data'], $result['status'], JsonResponse::HTTP_OK);
    }

    public function saveRouterConfig(Request $request, MikrotikInfoUseCaseInterface $uc): object
    {
        $data = $request->only(['name', 'host', 'user', 'pass', 'port']);
        if (empty($data['host']) || empty($data['user']) || empty($data['pass'])) {
            return standardApiReponse('host, user y pass son requeridos', null, 1, JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }
        $parsed = RouterHostParser::parse($data['host'], $data['port'] ?? null);
        $data['host'] = $parsed['host'];
        $data['port'] = $parsed['port'];
        $result = $uc->saveRouterConfig($data);
        return standardApiReponse($result['message'], $result['data'], $result['status'], JsonResponse::HTTP_OK);
    }

    public function getRouterInfo(Request $request, MikrotikInfoUseCaseInterface $uc): object
    {
        $result = $uc->getRouterInfo($this->routerId($request));
        return standardApiReponse($result['message'], $result['data'], $result['status'], JsonResponse::HTTP_OK);
    }

    public function getConnectedClients(Request $request, MikrotikInfoUseCaseInterface $uc): object
    {
        $result = $uc->getConnectedClients($this->routerId($request));
        return standardApiReponse($result['message'], $result['data'], $result['status'], JsonResponse::HTTP_OK);
    }

    public function getQueues(Request $request, MikrotikInfoUseCaseInterface $uc): object
    {
        $result = $uc->getQueues($this->routerId($request));
        return standardApiReponse($result['message'], $result['data'], $result['status'], JsonResponse::HTTP_OK);
    }

    public function createQueue(Request $request, MikrotikInfoUseCaseInterface $uc): object
    {
        $result = $uc->createQueue($request->except('router_id'), $this->routerId($request));
        return standardApiReponse($result['message'], $result['data'], $result['status'], JsonResponse::HTTP_OK);
    }

    public function updateQueue(Request $request, string $id, MikrotikInfoUseCaseInterface $uc): object
    {
        $result = $uc->updateQueue($id, $request->except('router_id'), $this->routerId($request));
        return standardApiReponse($result['message'], $result['data'], $result['status'], JsonResponse::HTTP_OK);
    }

    public function deleteQueue(Request $request, string $id, MikrotikInfoUseCaseInterface $uc): object
    {
        $result = $uc->deleteQueue($id, $this->routerId($request));
        return standardApiReponse($result['message'], $result['data'], $result['status'], JsonResponse::HTTP_OK);
    }

    public function suspendBulk(Request $request, MikrotikInfoUseCaseInterface $uc): object
    {
        $userIds = $request->input('user_ids', []);
        if (empty($userIds) || !is_array($userIds)) {
            return standardApiReponse('user_ids requerido', null, 1, JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }
        $result = $uc->suspendBulk($userIds, $this->routerId($request));
        return standardApiReponse($result['message'], $result['data'], $result['status'], JsonResponse::HTTP_OK);
    }

    public function UpdateStatus(
        UpdateStatusUserUseCaseInterface $updateStatusUserUseCaseInterface,
        GestionUserRequest $gestionUserRequest
    ): object {
        $result = $updateStatusUserUseCaseInterface->UpdateStatus($gestionUserRequest);
        return standardApiReponse($result['message'], $result['data'], $result['status'], JsonResponse::HTTP_OK);
    }

    public function getCpuStatus()
    {
        // Deshabilitada: entraba a la OLT de Netplay con IP y credenciales fijas en el
        // código, sin importar la empresa. Las consultas de OLT van por olt/{oltId}.
        return response()->json(['error' => 'Función deshabilitada'], 410);
    }

    public function getOntStatusAll()
    {
        // Deshabilitada: entraba a la OLT de Netplay con IP y credenciales fijas en el
        // código, sin importar la empresa. Las consultas de OLT van por olt/{oltId}.
        return response()->json(['error' => 'Función deshabilitada'], 410);
    }



    public function obtenerInformacionSNMP(Request $request): JsonResponse
    {
        $oltId = $request->input('olt_id', 5);
        // Sólo OLT de la propia empresa (el id va en la consulta, no en la ruta).
        $olt   = OltAdmin::where('company_id', getSessionCompanyId())->findOrFail($oltId);

        $onts = (new HuaweiSnmpReader($olt))->getAuthorizedONTs();

        if (empty($onts)) {
            return response()->json(['error' => 'No se obtuvieron datos SNMP del OLT'], 502);
        }

        return response()->json($onts, 200, [], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
    

    public function getOntPort()
    {
        // Deshabilitada: entraba a la OLT de Netplay con IP y credenciales fijas en el
        // código, sin importar la empresa. Las consultas de OLT van por olt/{oltId}.
        return response()->json(['error' => 'Función deshabilitada'], 410);
    }

    public function registerOnt(OltDataRequest $oltDataRequest)
    {
        // Deshabilitada: entraba a la OLT de Netplay con IP y credenciales fijas en el
        // código, sin importar la empresa. Las consultas de OLT van por olt/{oltId}.
        return response()->json(['error' => 'Función deshabilitada'], 410);
    }
    
    public function deleteontOnt(OltDataRequest $oltDataRequest)
    {
        // Deshabilitada: entraba a la OLT de Netplay con IP y credenciales fijas en el
        // código, sin importar la empresa. Las consultas de OLT van por olt/{oltId}.
        return response()->json(['error' => 'Función deshabilitada'], 410);
    }
    
    
}
