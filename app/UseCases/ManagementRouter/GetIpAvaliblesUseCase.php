<?php

namespace App\UseCases\ManagementRouter;

use App\Repositories\Interfaces\DniRepositoryInterface;
use Illuminate\Database\QueryException;
use App\Constants\ApiResponseConstants;
use App\Http\Requests\Gestions\GestionUserRequest;
use App\Managers\Interfaces\ConectionRouterManagerInterface;
use App\Repositories\Interfaces\ManagementRouterRepositoryInterface;
use App\Repositories\Interfaces\UserRepositoryInterface;
use App\UseCases\ManagementRouter\Interfaces\GetIpAvaliblesUseCaseInterface;
use RouterOS\Exceptions\QueryException as ExceptionsQueryException;
use RouterOS\Query;

use function Safe\json_encode;

/**
 * Clase del caso de uso GetCountrysUseCase
 *
 * @package App\UseCases\Pqr
 * @author Network Golden <sa.networkgolden@gmail.com>
 * @copyright 2022/06/13
 */
class GetIpAvaliblesUseCase implements GetIpAvaliblesUseCaseInterface
{
    /**
     * Constructor de la clase
     *
     * @param DniRepositoryInterface $dniRepositoryInterface
     */
    protected  $connection;

    public function __construct(
        private ManagementRouterRepositoryInterface $managementRouterRepositoryInterface,
        private ConectionRouterManagerInterface $conectionRouterManagerInterface,
        private UserRepositoryInterface $userRepositoryInterface,
        private \App\Repositories\Interfaces\RouterRepositoryInterface $routerRepositoryInterface,
        private \App\Repositories\Interfaces\InternetInfoRepositoryInterface $internetInfoRepositoryInterface,
    ) {
        $this->connection = $conectionRouterManagerInterface;
    }

    private function resolveToken(?int $routerId = null): string
    {
        $companyId = getSessionCompanyId();
        if (!$companyId) {
            throw new \RuntimeException('Sesión sin empresa asociada');
        }

        if ($routerId) {
            $router = $this->routerRepositoryInterface->getRouterById($routerId, $companyId);
            if (!$router) {
                throw new \RuntimeException('Router no encontrado o no pertenece a esta empresa');
            }
            return $router->token;
        }

        $token = $this->routerRepositoryInterface->getTokenByCompany($companyId);
        if (!$token) {
            throw new \RuntimeException('No hay router configurado para esta empresa');
        }
        return $token;
    }

    /** @deprecated use resolveToken() */
    private function getCompanyRouterId(): string
    {
        return $this->resolveToken();
    }

    /**
     * @return mixed
     * @param GestionUserRequest $gestionUserRequest
     */
public function GetIpAvalibles(GestionUserRequest $gestionUserRequest, ?int $routerId = null): mixed
{
    try {
        if (sessionUserHasProfile('USER')) {
            return ['message' => 'Acción no permitida', 'status' => 1, 'data' => null];
        }

        $vlan = $gestionUserRequest['vlan'];
        $api  = $this->connection->conection($this->resolveToken($routerId));

        /** 1️⃣ RED DE LA VLAN */
        $query = new Query('/ip/address/print');
        $query->add('=.proplist=address');
        $query->where('interface', $vlan);

        $address = $api->query($query)->read();

        if (empty($address)) {
            return [
                'message' => 'La VLAN no tiene red asignada',
                'status'  => 1,
                'data'    => null
            ];
        }

        // Se recorre el segmento con su máscara real. Antes se asumía /24 y se
        // cortaba en las primeras 20 libres: para usar una IP puntual había que
        // ir a buscarla al router.
        [$gateway, $bits] = array_pad(explode('/', $address[0]['address']), 2, '24');
        $bits = (int) $bits;
        if ($bits < 20 || $bits > 30) {
            $bits = 24;
        }
        $mascara  = -1 << (32 - $bits);
        $red      = ip2long($gateway) & $mascara;
        $difusion = $red | (~$mascara & 0xFFFFFFFF);

        /** 2️⃣ LO QUE HAY EN EL ROUTER */
        $query = new Query('/ip/arp/print');
        $query->add('=.proplist=address,mac-address,comment');
        $query->where('interface', $vlan);

        $enArp = [];
        foreach ($api->query($query)->read() as $fila) {
            if (!empty($fila['address'])) {
                $enArp[$fila['address']] = $fila;
            }
        }

        // El ARP solo ve lo que está prendido: la IP de un cliente apagado
        // desaparece de ahí y volvía a ofrecerse como libre. Así se entregó la
        // misma IP a varios clientes. Se descuentan también las que la
        // plataforma ya tiene registradas para esta empresa.
        $enPlataforma = [];
        $companyId = getSessionCompanyId();

        if ($companyId) {
            $filas = \Illuminate\Support\Facades\DB::table('tabla_ips as t')
                ->join('user_data as ud', 'ud.ip_assignment_id', '=', 't.id')
                ->where('t.company_id', $companyId)
                ->whereNotNull('t.ip')
                ->orderByDesc('ud.active')
                ->get(['t.ip', 'ud.names', 'ud.lastname', 'ud.active', 'ud.user_id']);

            foreach ($filas as $f) {
                $enPlataforma[trim($f->ip)] ??= $f; // si hay dos, manda el activo
            }
        }

        /** 3️⃣ LIBRES Y OCUPADAS (con quién las tiene) */
        $libres   = [];
        $ocupadas = [];

        for ($n = $red + 1; $n < $difusion; $n++) {
            $ip = long2ip($n);

            if ($ip === $gateway) {
                $ocupadas[] = ['ip' => $ip, 'estado' => 'gateway', 'detalle' => 'Puerta de enlace del router'];
                continue;
            }

            if (isset($enPlataforma[$ip])) {
                $c = $enPlataforma[$ip];
                $nombre = trim(($c->names ?? '') . ' ' . ($c->lastname ?? '')) ?: 'Cliente #' . $c->user_id;
                $ocupadas[] = [
                    'ip'      => $ip,
                    'estado'  => 'cliente',
                    'detalle' => $nombre . ((int) $c->active === 1 ? '' : ' (retirado)'),
                    'user_id' => (int) $c->user_id,
                ];
                continue;
            }

            if (isset($enArp[$ip])) {
                $a = $enArp[$ip];
                $ocupadas[] = [
                    'ip'      => $ip,
                    'estado'  => 'arp',
                    'detalle' => 'En el router sin cliente' . (!empty($a['comment']) ? ' · ' . $a['comment'] : (!empty($a['mac-address']) ? ' · ' . $a['mac-address'] : '')),
                ];
                continue;
            }

            $libres[] = ['ip' => $ip];
        }

        return [
            'message' => 'IPs disponibles encontradas',
            'status'  => 0,
            'data'    => [
                'vlan'     => $vlan,
                'gateway'  => $gateway,
                'network'  => long2ip($red) . '/' . $bits,
                'ips'      => $libres,
                'ocupadas' => $ocupadas,
            ]
        ];

    } catch (\Throwable $err) {

        // Hasta acá el error se perdía entero, así que cuando el formulario de
        // alta se trababa no había forma de saber si era el router apagado, la
        // clave cambiada o un timeout.
        \Illuminate\Support\Facades\Log::warning('[IPs disponibles] Falló la consulta al router', [
            'router_id' => $routerId,
            'vlan'      => $gestionUserRequest['vlan'] ?? null,
            'error'     => $err->getMessage(),
        ]);

        return [
            'message' => 'No se pudo consultar el router. Verificá que esté en línea y reintentá.',
            'status'  => 1,
            'data'    => null
        ];
    }
}



public function getLanSegments(?int $routerId = null, bool $todas = false): mixed
{
    try {

        // Solo bloquear perfil 1 (usuario regular)
        if (sessionUserHasProfile('USER')) {
            return [
                'message' => 'Acción no permitida',
                'status'  => 1,
                'data'    => null
            ];
        }

        $maxAttempts = 3;
        $segments = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $api = $this->connection->conection($this->resolveToken($routerId));
                // Sólo las redes que dan internet a los clientes; con $todas,
                // también la WAN, el servidor, las OLT y la gestión.
                $segments = (new \App\Services\Red\RedesParaClientes((int) getSessionCompanyId(), $routerId))
                    ->listar($api, $todas);
                break;
            } catch (\Throwable $e) {
                if ($attempt === $maxAttempts) throw $e;
                sleep(1);
            }
        }

        if (empty($segments)) {
            return [
                'message' => $todas
                    ? 'No se encontraron direcciones IP'
                    : 'No se encontraron redes de clientes en el router. Probá con "Ver todas".',
                'status'  => 1,
                'data'    => []
            ];
        }

        return [
            'message' => 'Segmentos LAN obtenidos correctamente',
            'status'  => 0,
            'data'    => $segments
        ];

    } catch (ExceptionsQueryException $err) {

        error_log('MIKROTIK ERROR: ' . $err->getMessage());

        return [
            'message' => 'Ocurrió un error al consultar el router',
            'status'  => 1,
            'data'    => null
        ];
    }
}


public function autorizarServicio(GestionUserRequest $request, ?int $routerId = null): array
{
    try {

        $dataUser = $this->userRepositoryInterface
            ->getUserById($request['service_id']);

        $api = $this->connection->conection($this->resolveToken($routerId));

        // 🔹 Buscar ARP existente
        $query = new Query('/ip/arp/print');
        $query->where('comment', $dataUser['dni']);
        $query->add('=.proplist=.id');

        $exists = $api->query($query)->read();

        // ❌ NO existe → mensaje
        if (empty($exists)) {
            return [
                'message' => 'El cliente no tiene ARP registrado, no se puede actualizar',
                'data'    => null,
                'status'  => ApiResponseConstants::ERROR
            ];
        }

        // ✔️ Existe → actualizar MAC
        $arpId = $exists[0]['.id'];
        $mac   = $request['mac'] ?: '00:00:00:00:00:00';

        $query = new Query('/ip/arp/set');
        $query->equal('.id', $arpId);
        $query->equal('mac-address', $mac);

        $api->query($query)->read();

        // Guardar MAC en TablaIp
        $arpQuery = new Query('/ip/arp/print');
        $arpQuery->where('.id', $arpId);
        $arpQuery->add('=.proplist=address');
        $arpResult = $api->query($arpQuery)->read();
        if (!empty($arpResult[0]['address'])) {
            $this->internetInfoRepositoryInterface->updateIpMac($arpResult[0]['address'], $mac);
        }

        return [
            'message' => 'MAC actualizada correctamente',
            'data'    => true,
            'status'  => ApiResponseConstants::SUCCESS
        ];

    } catch (\Throwable $e) {
        return [
            'message' => 'Error al actualizar MAC: ' . $e->getMessage(),
            'data'    => null,
            'status'  => ApiResponseConstants::ERROR
        ];
    }
}




public function registerIpInArp(string $ip, string $mac, string $vlan, string $comment, ?int $routerId = null): bool
{
    try {
        $api = $this->connection->conection($this->resolveToken($routerId));

        /**
         * 🔹 VALIDAR SI YA EXISTE EN ARP
         */
        $query = new Query('/ip/arp/print');
        $query->where('address', $ip);
        $query->add('=.proplist=.id');

        $exists = $api->query($query)->read();

        \Log::info("exists", ["exists" => $exists]);

        if (!empty($exists)) {
            // Ya existe, no volver a crear
            return true;
        }

        if($mac == ''){
          $mac = '00:00:00:00:00:00';
        }

        /**
         * 🔹 CREAR REGISTRO ARP
         */
        $query = new Query('/ip/arp/add');
        $query->equal('address', $ip);
        $query->equal('mac-address', $mac);
        $query->equal('interface', $vlan);
        $query->equal('comment', $comment);
        //$query->equal('published', 'yes'); // opcional, recomendado ISP

        $api->query($query)->read();

        \Log::info("api ABAJO", ["api" => $api]);


        return true;

    } catch (QueryException $e) {

        \Log::error('MIKROTIK ARP ERROR', [
            'ip' => $ip,
            'mac' => $mac,
            'vlan' => $vlan,
            'error' => $e->getMessage()
        ]);

        return false;
    }
}


    public function migrarIp(GestionUserRequest $request, ?int $routerId = null): array
    {
        try {
            $userId = (int) $request['service_id'];
            $newIp  = $request['new_ip'] ?? '';
            $vlan   = $request['vlan'] ?? '';

            if (!$userId || !$newIp || !$vlan) {
                return ['message' => 'Faltan parámetros: service_id, new_ip, vlan', 'status' => 1, 'data' => null];
            }

            $dataUser = $this->userRepositoryInterface->getUserById($userId);
            if (!$dataUser) {
                return ['message' => 'Usuario no encontrado', 'status' => 1, 'data' => null];
            }

            $dni = $dataUser['dni'];
            $api = $this->connection->conection($this->resolveToken($routerId));

            // 1️⃣ Eliminar ARP anterior del cliente (busca por comment = DNI)
            $query = new Query('/ip/arp/print');
            $query->where('comment', $dni);
            $query->add('=.proplist=.id');
            $existing = $api->query($query)->read();

            foreach ($existing as $entry) {
                $del = new Query('/ip/arp/remove');
                $del->equal('.id', $entry['.id']);
                $api->query($del)->read();
            }

            // 2️⃣ Crear nuevo ARP con la nueva IP
            $query = new Query('/ip/arp/add');
            $query->equal('address', $newIp);
            $query->equal('mac-address', '00:00:00:00:00:00');
            $query->equal('interface', $vlan);
            $query->equal('comment', $dni);
            $api->query($query)->read();

            // 3️⃣ Actualizar IP en base de datos
            $this->internetInfoRepositoryInterface->updateUserIp($userId, $newIp);

            \Log::info('IP MIGRADA', ['user_id' => $userId, 'new_ip' => $newIp, 'vlan' => $vlan]);

            return ['message' => 'IP migrada correctamente', 'status' => 0, 'data' => ['ip' => $newIp, 'vlan' => $vlan]];

        } catch (\Throwable $e) {
            \Log::error('ERROR MIGRACION IP', ['error' => $e->getMessage()]);
            return ['message' => 'Error al migrar IP: ' . $e->getMessage(), 'status' => 1, 'data' => null];
        }
    }


    public function validateMikrotikConnection(): bool
{
    try {
        // Intentar establecer una conexión con MikroTik
        $connection = $this->connection->conection($this->getCompanyRouterId());
        
        // Realizar una consulta de prueba
        $query = new Query('/system/resource/print');
        $response = $connection->query($query)->read();

        // Verificar si la respuesta es válida
        if (!empty($response)) {
            return true; // Conexión exitosa
        }
    } catch (ExceptionsQueryException $err) {
        error_log("Error de conexión a MikroTik: " . $err->getMessage());
    }
    
    return false; // Fallo en la conexión
}
}

