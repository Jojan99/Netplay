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

        /** 1️⃣ REDES DE LA VLAN */
        // Una VLAN puede tener varias redes (vlan10 con la .10 y la .11). Antes
        // se usaba sólo la primera y las IP de las demás no se ofrecían nunca.
        $redes = \App\Services\Red\IpFijaEnElRouter::redesDe($api, (string) $vlan);

        if (empty($redes)) {
            return [
                'message' => 'La VLAN no tiene red asignada',
                'status'  => 1,
                'data'    => null
            ];
        }

        /** 2️⃣ LO QUE HAY EN EL ROUTER */
        $query = new Query('/ip/arp/print');
        $query->add('=.proplist=address,mac-address,comment,disabled,dynamic');
        $query->where('interface', $vlan);

        $enArp = [];
        foreach ($api->query($query)->read() as $fila) {
            if (!empty($fila['address'])) {
                $enArp[$fila['address']] ??= $fila;
            }
        }

        // El ARP solo ve lo que está prendido: la IP de un cliente apagado
        // desaparece de ahí y volvía a ofrecerse como libre. Así se entregó la
        // misma IP a varios clientes. Se descuentan también las que la
        // plataforma ya tiene registradas para esta empresa.
        $enPlataforma = [];
        $companyId = (int) getSessionCompanyId();

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

        /** 3️⃣ LIBRES Y OCUPADAS DE CADA RED (con quién las tiene) */
        foreach ($redes as &$r) {
            [$r['libres'], $r['ocupadas']] = $this->clasificarRed($r, $enPlataforma, $enArp, $companyId);
        }
        unset($r);

        // La red que se muestra: la pedida; si no, la de la IP que el cliente
        // ya tiene; si no, la primera con IP libres.
        $pedida = trim((string) ($gestionUserRequest['segment'] ?? ''));
        $elegida = ($pedida !== '' ? \App\Services\Red\IpFijaEnElRouter::redDe($redes, $pedida) : null)
            ?? \App\Services\Red\IpFijaEnElRouter::redDe($redes, (string) ($gestionUserRequest['ip_actual'] ?? ''))
            ?? (collect($redes)->first(fn ($r) => count($r['libres']) > 0) ?? $redes[0]);

        $resumen = array_map(fn ($r) => [
            'network'     => $r['network'],
            'gateway'     => $r['gateway'],
            'mask'        => $r['mask'],
            'netmask'     => $r['netmask'],
            'libres'      => count($r['libres']),
            'clientes'    => count(array_filter($r['ocupadas'], fn ($o) => $o['estado'] === 'cliente')),
            'sin_cliente' => count(array_filter($r['ocupadas'], fn ($o) => $o['estado'] === 'arp')),
            'elegida'     => $r['network'] === $elegida['network'],
        ], $redes);

        return [
            'message' => 'IPs disponibles encontradas',
            'status'  => 0,
            'data'    => [
                // Los mismos campos de siempre, de la red elegida.
                'vlan'     => $vlan,
                'gateway'  => $elegida['gateway'],
                'network'  => $elegida['network'],
                'mask'     => $elegida['mask'],
                'netmask'  => $elegida['netmask'],
                'ips'      => $elegida['libres'],
                'ocupadas' => $elegida['ocupadas'],
                // Todas las redes de la VLAN, para elegir entre ellas.
                'redes'    => $resumen,
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



/**
 * Las IP de una red: libres, y ocupadas con quién las tiene. Las que están en
 * el router sin cliente de la plataforma van como 'arp', con su MAC y su
 * comment: se pueden asignar reutilizando esa entrada.
 *
 * @return array{0: list<array>, 1: list<array>}
 */
private function clasificarRed(array $r, array $enPlataforma, array $enArp, int $companyId): array
{
    $libres   = [];
    $ocupadas = [];

    for ($n = $r['red'] + 1; $n < $r['difusion']; $n++) {
        $ip = long2ip($n);

        if ($ip === $r['gateway']) {
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
            // La entrada puede ser de un cliente aunque la plataforma tenga
            // otra IP en su ficha: el comment puede traer el nombre que
            // tenía en la plataforma de la que se importó.
            $dueno = \App\Services\Red\IdentidadEnElRouter::clienteDeEntrada($companyId, $a, false);

            if ($dueno) {
                $ocupadas[] = [
                    'ip'      => $ip,
                    'estado'  => 'cliente',
                    'detalle' => $dueno['nombre'] . ' (en el router, la ficha tiene otra IP)',
                    'user_id' => $dueno['user_id'],
                ];
                continue;
            }

            $comment = trim((string) ($a['comment'] ?? ''));
            $mac     = trim((string) ($a['mac-address'] ?? ''));
            $ocupadas[] = [
                'ip'      => $ip,
                'estado'  => 'arp',
                'detalle' => 'En el router sin cliente' . ($comment !== '' ? ' · ' . $comment : ($mac !== '' ? ' · ' . $mac : '')),
                'user_id' => null,
                'mac'     => $mac !== '' ? $mac : null,
                'comment' => $comment !== '' ? $comment : null,
                'desactivada' => ($a['disabled'] ?? 'false') === 'true',
            ];
            continue;
        }

        $libres[] = ['ip' => $ip];
    }

    return [$libres, $ocupadas];
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

        // 🔹 Buscar ARP existente (por documento, por el nombre que traía de la
        // plataforma de origen o por su IP).
        $companyId = (int) getSessionCompanyId();
        $identidad = \App\Services\Red\IdentidadEnElRouter::deDocumento((string) $dataUser['dni'], $companyId);

        $query = new Query('/ip/arp/print');
        $query->add('=.proplist=.id,address,comment');

        $exists = $identidad
            ? \App\Services\Red\IdentidadEnElRouter::suyas($api->query($query)->read(), $identidad, $companyId)
            : [];

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
    return $this->asegurarIpEnArp($ip, $vlan, $comment, null, $routerId, $mac)['ok'];
}

/**
 * Deja la IP fija del cliente en el ARP del router. Si la IP ya está en el
 * router sin cliente de la plataforma, se reutiliza esa entrada (con su MAC)
 * en vez de crear otra; si la tiene otro cliente, se rechaza.
 *
 * @return array{ok:bool, mensaje:string, accion:?string, comment_anterior:?string, mac:?string}
 */
public function asegurarIpEnArp(string $ip, string $vlan, string $documento, ?int $userId = null, ?int $routerId = null, string $mac = ''): array
{
    try {
        $api = $this->connection->conection($this->resolveToken($routerId));
        $ipFija = new \App\Services\Red\IpFijaEnElRouter($api, (int) getSessionCompanyId());

        $revision = $ipFija->revisar($ip, $vlan, $userId);
        if (!$revision['ok']) {
            return ['ok' => false, 'mensaje' => $revision['mensaje'], 'accion' => null, 'comment_anterior' => null, 'mac' => null];
        }

        return ['ok' => true] + $ipFija->aplicar($revision, $ip, $vlan, $documento, $mac);

    } catch (\Throwable $e) {

        \Log::error('MIKROTIK ARP ERROR', [
            'ip' => $ip,
            'mac' => $mac,
            'vlan' => $vlan,
            'error' => $e->getMessage()
        ]);

        return ['ok' => false, 'mensaje' => 'Error registrando el cliente en el MikroTik. Contacte al administrador.', 'accion' => null, 'comment_anterior' => null, 'mac' => null];
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

            $companyId = (int) getSessionCompanyId();
            $identidad = \App\Services\Red\IdentidadEnElRouter::deUsuario((int) $userId, $companyId);
            if (!$identidad) {
                return ['message' => 'Cliente no encontrado en esta empresa', 'status' => 1, 'data' => null];
            }

            // 0️⃣ Antes de tocar nada: la IP no puede ser de otro cliente y tiene
            // que ser de alguna red de esa VLAN (puede tener varias).
            $ipFija   = new \App\Services\Red\IpFijaEnElRouter($api, $companyId);
            $revision = $ipFija->revisar($newIp, $vlan, $userId);
            if (!$revision['ok']) {
                return ['message' => $revision['mensaje'], 'status' => 1, 'data' => null];
            }

            // 1️⃣ Eliminar ARP anterior del cliente. Se lo reconoce por su
            // documento, por el nombre que traía de la plataforma de origen o
            // por la IP de su ficha. La entrada de la IP nueva no se toca.
            $query = new Query('/ip/arp/print');
            $query->add('=.proplist=.id,address,comment,mac-address');
            $existing = array_values(array_filter(
                \App\Services\Red\IdentidadEnElRouter::suyas($api->query($query)->read(), $identidad, $companyId),
                fn ($e) => ($e['address'] ?? '') !== $newIp
            ));

            // El equipo es el mismo: su MAC también. Con 00:00:00:00:00:00 y el
            // ARP en reply-only el router deja de contestarle.
            $macAnterior = collect($existing)->pluck('mac-address')
                ->first(fn ($m) => $m && $m !== '00:00:00:00:00:00') ?? '00:00:00:00:00:00';

            foreach ($existing as $entry) {
                $del = new Query('/ip/arp/remove');
                $del->equal('.id', $entry['.id']);
                $api->query($del)->read();
            }

            // 2️⃣ La entrada de la IP nueva: se crea, o se reutiliza la que ya
            // estaba en el router sin cliente (con su MAC).
            $arp = $ipFija->aplicar($revision, $newIp, $vlan, (string) $dni, $macAnterior);
            if ($arp['accion'] === 'reutilizada') {
                \App\Services\Red\IpFijaEnElRouter::recordarNombreAnterior($companyId, $userId, $arp['comment_anterior'], (string) $dni);
            }

            // 3️⃣ Actualizar IP en base de datos
            $this->internetInfoRepositoryInterface->updateUserIp($userId, $newIp);

            if (!empty($arp['mac'])) {
                $this->internetInfoRepositoryInterface->updateIpMac($newIp, $arp['mac']);
            }

            \Log::info('IP MIGRADA', ['user_id' => $userId, 'new_ip' => $newIp, 'vlan' => $vlan, 'arp' => $arp['accion']]);

            $red = $revision['red'];

            return [
                'message' => 'IP migrada correctamente' . ($arp['accion'] === 'reutilizada' ? '. ' . $arp['mensaje'] : ''),
                'status'  => 0,
                'data'    => ['ip' => $newIp, 'vlan' => $vlan, 'gateway' => $red['gateway'] ?? null, 'network' => $red['network'] ?? null, 'arp' => $arp['accion']],
            ];

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

