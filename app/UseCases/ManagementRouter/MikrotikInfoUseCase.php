<?php

namespace App\UseCases\ManagementRouter;

use App\Managers\Interfaces\ConectionRouterManagerInterface;
use App\Models\CabFacturation;
use App\Models\DetFacturation;
use App\Models\User;
use App\Models\UserData;
use App\Repositories\Interfaces\RouterRepositoryInterface;
use App\Services\WhatsAppService;
use App\UseCases\ManagementRouter\Interfaces\MikrotikInfoUseCaseInterface;
use RouterOS\Query;

class MikrotikInfoUseCase implements MikrotikInfoUseCaseInterface
{
    public function __construct(
        private ConectionRouterManagerInterface $conectionRouterManagerInterface,
        private RouterRepositoryInterface $routerRepositoryInterface,
    ) {}

    private function getToken(): string
    {
        $companyId = getSessionCompanyId();
        if (!$companyId) {
            throw new \RuntimeException('Sesión sin empresa asociada');
        }
        $token = $this->routerRepositoryInterface->getTokenByCompany($companyId);
        if (!$token) {
            throw new \RuntimeException('No hay router configurado para esta empresa');
        }
        return $token;
    }

    public function getRouterInfo(): array
    {
        try {
            $api = $this->conectionRouterManagerInterface->conection($this->getToken());

            // System resource
            $resource = $api->query(new Query('/system/resource/print'))->read();
            $resource = $resource[0] ?? [];

            // Identity
            $identity = $api->query(new Query('/system/identity/print'))->read();
            $name = $identity[0]['name'] ?? 'Desconocido';

            // Interfaces
            $ifQuery = new Query('/interface/print');
            $ifQuery->add('=.proplist=name,type,running,disabled,tx-byte,rx-byte,tx-packet,rx-packet,mac-address,mtu');
            $interfaces = $api->query($ifQuery)->read();

            // IP Addresses
            $addrQuery = new Query('/ip/address/print');
            $addrQuery->add('=.proplist=address,interface,disabled');
            $addresses = $api->query($addrQuery)->read();

            // ARP table (connected clients)
            $arpQuery = new Query('/ip/arp/print');
            $arpQuery->add('=.proplist=address,mac-address,interface,comment,disabled');
            $arpList = $api->query($arpQuery)->read();

            return [
                'status'  => 0,
                'message' => 'Información del router obtenida correctamente',
                'data'    => [
                    'identity'   => $name,
                    'resource'   => [
                        'platform'      => $resource['platform'] ?? '',
                        'board_name'    => $resource['board-name'] ?? '',
                        'version'       => $resource['version'] ?? '',
                        'uptime'        => $resource['uptime'] ?? '',
                        'cpu_load'      => $resource['cpu-load'] ?? 0,
                        'free_memory'   => $resource['free-memory'] ?? 0,
                        'total_memory'  => $resource['total-memory'] ?? 0,
                        'free_hdd'      => $resource['free-hdd-space'] ?? 0,
                        'total_hdd'     => $resource['total-hdd-space'] ?? 0,
                        'cpu_count'     => $resource['cpu-count'] ?? 1,
                        'architecture'  => $resource['architecture-name'] ?? '',
                    ],
                    'interfaces' => $interfaces,
                    'addresses'  => $addresses,
                    'arp_count'  => count($arpList),
                    'active_clients' => count(array_filter($arpList, fn($r) => ($r['disabled'] ?? 'false') === 'false')),
                ],
            ];
        } catch (\Throwable $e) {
            return ['status' => 1, 'message' => 'Error al obtener info del router: ' . $e->getMessage(), 'data' => null];
        }
    }

    public function getConnectedClients(): array
    {
        try {
            $api = $this->conectionRouterManagerInterface->conection($this->getToken());

            $arpQuery = new Query('/ip/arp/print');
            $arpQuery->add('=.proplist=.id,address,mac-address,interface,comment,disabled');
            $arpList = $api->query($arpQuery)->read();

            // Enrich with user data from DB
            $users = UserData::select(
                    'user_data.user_id',
                    'user_data.dni',
                    'user_data.names',
                    'user_data.lastname',
                    'users.username',
                    'user_data.status_internet_id'
                )
                ->join('users', 'users.id', '=', 'user_data.user_id')
                ->where('users.company_id', getSessionCompanyId())
                ->get()
                ->keyBy('dni')
                ->map(fn($u) => $u->toArray())
                ->toArray();

            $clients = array_map(function ($arp) use ($users) {
                $comment = $arp['comment'] ?? '';
                $userData = $users[$comment] ?? null;
                return [
                    'id'         => $arp['.id'] ?? '',
                    'ip'         => $arp['address'] ?? '',
                    'mac'        => $arp['mac-address'] ?? '',
                    'interface'  => $arp['interface'] ?? '',
                    'comment'    => $comment,
                    'disabled'   => ($arp['disabled'] ?? 'false') === 'true',
                    'user_name'  => isset($userData['names']) ? trim($userData['names'] . ' ' . ($userData['lastname'] ?? '')) : null,
                    'username'   => $userData['username'] ?? null,
                    'user_id'    => $userData['user_id'] ?? null,
                    'status_id'  => $userData['status_internet_id'] ?? null,
                ];
            }, $arpList);

            return ['status' => 0, 'message' => 'Clientes obtenidos', 'data' => $clients];
        } catch (\Throwable $e) {
            return ['status' => 1, 'message' => 'Error: ' . $e->getMessage(), 'data' => null];
        }
    }

    public function getQueues(): array
    {
        try {
            $api = $this->conectionRouterManagerInterface->conection($this->getToken());

            $query = new Query('/queue/simple/print');
            $query->add('=.proplist=.id,name,target,max-limit,burst-limit,burst-threshold,burst-time,comment,disabled');
            $queues = $api->query($query)->read();

            return ['status' => 0, 'message' => 'Colas obtenidas', 'data' => $queues];
        } catch (\Throwable $e) {
            return ['status' => 1, 'message' => 'Error al obtener colas: ' . $e->getMessage(), 'data' => null];
        }
    }

    public function createQueue(array $data): array
    {
        try {
            $api = $this->conectionRouterManagerInterface->conection($this->getToken());

            $query = new Query('/queue/simple/add');
            $query->equal('name', $data['name']);
            $query->equal('target', $data['target']);                // e.g. "192.168.1.10/32"
            $query->equal('max-limit', $data['max_limit']);          // e.g. "5M/2M"
            if (!empty($data['comment'])) {
                $query->equal('comment', $data['comment']);
            }
            if (!empty($data['burst_limit'])) {
                $query->equal('burst-limit', $data['burst_limit']);
            }
            if (!empty($data['burst_threshold'])) {
                $query->equal('burst-threshold', $data['burst_threshold']);
            }
            if (!empty($data['burst_time'])) {
                $query->equal('burst-time', $data['burst_time']);
            }

            $api->query($query)->read();

            return ['status' => 0, 'message' => 'Cola creada correctamente', 'data' => true];
        } catch (\Throwable $e) {
            return ['status' => 1, 'message' => 'Error al crear cola: ' . $e->getMessage(), 'data' => null];
        }
    }

    public function updateQueue(string $id, array $data): array
    {
        try {
            $api = $this->conectionRouterManagerInterface->conection($this->getToken());

            $query = new Query('/queue/simple/set');
            $query->equal('.id', $id);
            if (isset($data['name']))       $query->equal('name', $data['name']);
            if (isset($data['target']))     $query->equal('target', $data['target']);
            if (isset($data['max_limit']))  $query->equal('max-limit', $data['max_limit']);
            if (isset($data['comment']))    $query->equal('comment', $data['comment']);
            if (isset($data['disabled']))   $query->equal('disabled', $data['disabled'] ? 'yes' : 'no');
            if (isset($data['burst_limit'])) $query->equal('burst-limit', $data['burst_limit']);
            if (isset($data['burst_threshold'])) $query->equal('burst-threshold', $data['burst_threshold']);
            if (isset($data['burst_time'])) $query->equal('burst-time', $data['burst_time']);

            $api->query($query)->read();

            return ['status' => 0, 'message' => 'Cola actualizada correctamente', 'data' => true];
        } catch (\Throwable $e) {
            return ['status' => 1, 'message' => 'Error al actualizar cola: ' . $e->getMessage(), 'data' => null];
        }
    }

    public function deleteQueue(string $id): array
    {
        try {
            $api = $this->conectionRouterManagerInterface->conection($this->getToken());

            $query = new Query('/queue/simple/remove');
            $query->equal('.id', $id);
            $api->query($query)->read();

            return ['status' => 0, 'message' => 'Cola eliminada correctamente', 'data' => true];
        } catch (\Throwable $e) {
            return ['status' => 1, 'message' => 'Error al eliminar cola: ' . $e->getMessage(), 'data' => null];
        }
    }

    public function getRouterConfig(): array
    {
        $companyId = getSessionCompanyId();
        $router = $this->routerRepositoryInterface->getRouterByCompany($companyId);

        if (!$router) {
            return ['status' => 0, 'message' => 'Sin configuración', 'data' => null];
        }

        return [
            'status'  => 0,
            'message' => 'Configuración obtenida',
            'data'    => [
                'host' => $router->host,
                'user' => $router->user,
                'port' => $router->port ?? 8728,
                // Never expose password
            ],
        ];
    }

    public function saveRouterConfig(array $data): array
    {
        try {
            $companyId = getSessionCompanyId();
            $router = $this->routerRepositoryInterface->saveRouterConfig($companyId, $data);
            return ['status' => 0, 'message' => 'Configuración guardada correctamente', 'data' => ['host' => $router->host, 'user' => $router->user, 'port' => $router->port]];
        } catch (\Throwable $e) {
            return ['status' => 1, 'message' => 'Error al guardar: ' . $e->getMessage(), 'data' => null];
        }
    }

    private function getPendingBalance(int $userId): float
    {
        $cab = CabFacturation::where('user_id', $userId)->first();
        if (!$cab) return 0.0;

        $pending = DetFacturation::where('cab_id', $cab->id)
            ->where('paid', '<>', 1)
            ->sum('price_total');

        return (float) $pending;
    }

    private function resolveSuspensionTemplate($userData, float $balance): string
    {
        $template = "🚫Estimado/a {nombre} {apellido}, le informamos que su servicio de internet ha sido *suspendido* por falta de pago.🚫\n\n"
                  . "💰 Saldo pendiente: *\${deuda}*\n\n"
                  . "Para reactivar su servicio, comuníquese con nosotros o realice su pago.\n\n"
                  . "📅 Fecha: {fecha}"
                  . "Medios de pago: BANCOLOMBIA CTA AHO 47800013328
                    DAVIPLATA 3022042294 (Hum Gom)
                    NEQUI 3022042294 (Hum Gom)
                    ";

        $vars = [
            '{nombre}'   => $userData->names ?? '',
            '{apellido}' => $userData->lastname ?? '',
            '{dni}'      => $userData->dni ?? '',
            '{telefono}' => $userData->phone ?? '',
            '{deuda}'    => number_format($balance, 0, '.', ','),
            '{fecha}'    => now()->format('d/m/Y'),
        ];

        return str_replace(array_keys($vars), array_values($vars), $template);
    }

    public function suspendBulk(array $userIds): array
    {
        $results = [];
        $errors  = [];

        try {
            $api = $this->conectionRouterManagerInterface->conection($this->getToken());

            foreach ($userIds as $userId) {
                try {
                    // Get user DNI (used as ARP comment)
                    $userData = UserData::where('user_data.user_id', $userId)
                        ->join('users', 'users.id', '=', 'user_data.user_id')
                        ->where('users.company_id', getSessionCompanyId())
                        ->select('user_data.*')
                        ->first();

                    if (!$userData) {
                        $errors[] = "User $userId: no encontrado";
                        continue;
                    }

                    $dni = $userData->dni;

                    // Find ARP by comment
                    $arpQuery = new Query('/ip/arp/print');
                    $arpQuery->where('comment', $dni);
                    $arpQuery->add('=.proplist=.id,address');
                    $arpEntries = $api->query($arpQuery)->read();

                    if (empty($arpEntries)) {
                        $errors[] = "User $userId ($dni): sin ARP";
                        continue;
                    }

                    foreach ($arpEntries as $arp) {
                        // Disable ARP
                        $disableQuery = new Query('/ip/arp/disable');
                        $disableQuery->equal('.id', $arp['.id']);
                        $api->query($disableQuery)->read();

                        // Add to morosos address-list
                        $ip = $arp['address'] ?? '';
                        if ($ip) {
                            $alQuery = new Query('/ip/firewall/address-list/add');
                            $alQuery->equal('list', 'morosos');
                            $alQuery->equal('address', $ip);
                            $alQuery->equal('comment', $dni);
                            $api->query($alQuery)->read();
                        }
                    }

                    // Update DB status
                    UserData::where('user_id', $userId)->update(['status_internet_id' => 2]);

                    // Send WhatsApp suspension notification
                    try {
                        if ($userData->phone) {
                            $balance = $this->getPendingBalance($userId);
                            $wa = new WhatsAppService();
                            $message = $this->resolveSuspensionTemplate($userData, $balance);
                            $wa->mensajeInformativo($userData->phone, $message);
                        }
                    } catch (\Throwable $waErr) {
                        // Non-critical: log and continue
                        error_log("WA suspension notify user $userId: " . $waErr->getMessage());
                    }

                    $results[] = $userId;
                } catch (\Throwable $inner) {
                    $errors[] = "User $userId: " . $inner->getMessage();
                }
            }

            return [
                'status'    => 0,
                'message'   => count($results) . ' cliente(s) suspendido(s)',
                'data'      => ['suspended' => $results, 'errors' => $errors],
            ];
        } catch (\Throwable $e) {
            return ['status' => 1, 'message' => 'Error de conexión: ' . $e->getMessage(), 'data' => null];
        }
    }
}
