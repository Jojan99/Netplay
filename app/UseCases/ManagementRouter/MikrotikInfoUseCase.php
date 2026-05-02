<?php

namespace App\UseCases\ManagementRouter;

use App\Managers\Interfaces\ConectionRouterManagerInterface;
use App\Models\CabFacturation;
use App\Models\DetFacturation;
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

    // ── Router CRUD ───────────────────────────────────────────────────────────

    public function listRouters(): array
    {
        try {
            $companyId = getSessionCompanyId();
            $routers = $this->routerRepositoryInterface->getRoutersByCompany($companyId);
            return ['status' => 0, 'message' => 'Routers obtenidos', 'data' => $routers];
        } catch (\Throwable $e) {
            return ['status' => 1, 'message' => $e->getMessage(), 'data' => null];
        }
    }

    public function createRouter(array $data): array
    {
        try {
            $companyId = getSessionCompanyId();
            $router = $this->routerRepositoryInterface->createRouter($companyId, $data);
            return ['status' => 0, 'message' => 'Router agregado correctamente', 'data' => $router];
        } catch (\Throwable $e) {
            return ['status' => 1, 'message' => $e->getMessage(), 'data' => null];
        }
    }

    public function updateRouter(int $id, array $data): array
    {
        try {
            $companyId = getSessionCompanyId();
            $router = $this->routerRepositoryInterface->updateRouter($id, $companyId, $data);
            if (!$router) {
                return ['status' => 1, 'message' => 'Router no encontrado', 'data' => null];
            }
            return ['status' => 0, 'message' => 'Router actualizado', 'data' => $router];
        } catch (\Throwable $e) {
            return ['status' => 1, 'message' => $e->getMessage(), 'data' => null];
        }
    }

    public function deleteRouter(int $id): array
    {
        try {
            $companyId = getSessionCompanyId();
            $deleted = $this->routerRepositoryInterface->deleteRouter($id, $companyId);
            if (!$deleted) {
                return ['status' => 1, 'message' => 'Router no encontrado', 'data' => null];
            }
            return ['status' => 0, 'message' => 'Router eliminado', 'data' => true];
        } catch (\Throwable $e) {
            return ['status' => 1, 'message' => $e->getMessage(), 'data' => null];
        }
    }

    // ── Router operations ──────────────────────────────────────────────────────

    public function getRouterInfo(?int $routerId = null): array
    {
        try {
            $api = $this->conectionRouterManagerInterface->conection($this->resolveToken($routerId));

            $resource  = $api->query(new Query('/system/resource/print'))->read()[0] ?? [];
            $identity  = $api->query(new Query('/system/identity/print'))->read();
            $name      = $identity[0]['name'] ?? 'Desconocido';

            $ifQuery = new Query('/interface/print');
            $ifQuery->add('=.proplist=name,type,running,disabled,tx-byte,rx-byte,tx-packet,rx-packet,mac-address,mtu');
            $interfaces = $api->query($ifQuery)->read();

            $addrQuery = new Query('/ip/address/print');
            $addrQuery->add('=.proplist=address,interface,disabled');
            $addresses = $api->query($addrQuery)->read();

            $arpQuery = new Query('/ip/arp/print');
            $arpQuery->add('=.proplist=address,mac-address,interface,comment,disabled');
            $arpList = $api->query($arpQuery)->read();

            return [
                'status'  => 0,
                'message' => 'Información del router obtenida correctamente',
                'data'    => [
                    'identity'       => $name,
                    'resource'       => [
                        'platform'     => $resource['platform'] ?? '',
                        'board_name'   => $resource['board-name'] ?? '',
                        'version'      => $resource['version'] ?? '',
                        'uptime'       => $resource['uptime'] ?? '',
                        'cpu_load'     => $resource['cpu-load'] ?? 0,
                        'free_memory'  => $resource['free-memory'] ?? 0,
                        'total_memory' => $resource['total-memory'] ?? 0,
                        'free_hdd'     => $resource['free-hdd-space'] ?? 0,
                        'total_hdd'    => $resource['total-hdd-space'] ?? 0,
                        'cpu_count'    => $resource['cpu-count'] ?? 1,
                        'architecture' => $resource['architecture-name'] ?? '',
                    ],
                    'interfaces'     => $interfaces,
                    'addresses'      => $addresses,
                    'arp_count'      => count($arpList),
                    'active_clients' => count(array_filter($arpList, fn($r) => ($r['disabled'] ?? 'false') === 'false')),
                ],
            ];
        } catch (\Throwable $e) {
            return ['status' => 1, 'message' => 'Error al obtener info del router: ' . $e->getMessage(), 'data' => null];
        }
    }

    public function getConnectedClients(?int $routerId = null): array
    {
        try {
            $api = $this->conectionRouterManagerInterface->conection($this->resolveToken($routerId));

            $arpQuery = new Query('/ip/arp/print');
            $arpQuery->add('=.proplist=.id,address,mac-address,interface,comment,disabled');
            $arpList = $api->query($arpQuery)->read();

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
                $comment  = $arp['comment'] ?? '';
                $userData = $users[$comment] ?? null;
                return [
                    'id'        => $arp['.id'] ?? '',
                    'ip'        => $arp['address'] ?? '',
                    'mac'       => $arp['mac-address'] ?? '',
                    'interface' => $arp['interface'] ?? '',
                    'comment'   => $comment,
                    'disabled'  => ($arp['disabled'] ?? 'false') === 'true',
                    'user_name' => isset($userData['names']) ? trim($userData['names'] . ' ' . ($userData['lastname'] ?? '')) : null,
                    'username'  => $userData['username'] ?? null,
                    'user_id'   => $userData['user_id'] ?? null,
                    'status_id' => $userData['status_internet_id'] ?? null,
                ];
            }, $arpList);

            return ['status' => 0, 'message' => 'Clientes obtenidos', 'data' => $clients];
        } catch (\Throwable $e) {
            return ['status' => 1, 'message' => 'Error: ' . $e->getMessage(), 'data' => null];
        }
    }

    public function getQueues(?int $routerId = null): array
    {
        try {
            $api = $this->conectionRouterManagerInterface->conection($this->resolveToken($routerId));

            $query = new Query('/queue/simple/print');
            $query->add('=.proplist=.id,name,target,max-limit,burst-limit,burst-threshold,burst-time,comment,disabled');
            $queues = $api->query($query)->read();

            return ['status' => 0, 'message' => 'Colas obtenidas', 'data' => $queues];
        } catch (\Throwable $e) {
            return ['status' => 1, 'message' => 'Error al obtener colas: ' . $e->getMessage(), 'data' => null];
        }
    }

    public function createQueue(array $data, ?int $routerId = null): array
    {
        try {
            $api = $this->conectionRouterManagerInterface->conection($this->resolveToken($routerId));

            $query = new Query('/queue/simple/add');
            $query->equal('name', $data['name']);
            $query->equal('target', $data['target']);
            $query->equal('max-limit', $data['max_limit']);
            if (!empty($data['comment']))          $query->equal('comment', $data['comment']);
            if (!empty($data['burst_limit']))      $query->equal('burst-limit', $data['burst_limit']);
            if (!empty($data['burst_threshold']))  $query->equal('burst-threshold', $data['burst_threshold']);
            if (!empty($data['burst_time']))       $query->equal('burst-time', $data['burst_time']);

            $api->query($query)->read();

            return ['status' => 0, 'message' => 'Cola creada correctamente', 'data' => true];
        } catch (\Throwable $e) {
            return ['status' => 1, 'message' => 'Error al crear cola: ' . $e->getMessage(), 'data' => null];
        }
    }

    public function updateQueue(string $id, array $data, ?int $routerId = null): array
    {
        try {
            $api = $this->conectionRouterManagerInterface->conection($this->resolveToken($routerId));

            $query = new Query('/queue/simple/set');
            $query->equal('.id', $id);
            if (isset($data['name']))            $query->equal('name', $data['name']);
            if (isset($data['target']))          $query->equal('target', $data['target']);
            if (isset($data['max_limit']))       $query->equal('max-limit', $data['max_limit']);
            if (isset($data['comment']))         $query->equal('comment', $data['comment']);
            if (isset($data['disabled']))        $query->equal('disabled', $data['disabled'] ? 'yes' : 'no');
            if (isset($data['burst_limit']))     $query->equal('burst-limit', $data['burst_limit']);
            if (isset($data['burst_threshold'])) $query->equal('burst-threshold', $data['burst_threshold']);
            if (isset($data['burst_time']))      $query->equal('burst-time', $data['burst_time']);

            $api->query($query)->read();

            return ['status' => 0, 'message' => 'Cola actualizada correctamente', 'data' => true];
        } catch (\Throwable $e) {
            return ['status' => 1, 'message' => 'Error al actualizar cola: ' . $e->getMessage(), 'data' => null];
        }
    }

    public function deleteQueue(string $id, ?int $routerId = null): array
    {
        try {
            $api = $this->conectionRouterManagerInterface->conection($this->resolveToken($routerId));

            $query = new Query('/queue/simple/remove');
            $query->equal('.id', $id);
            $api->query($query)->read();

            return ['status' => 0, 'message' => 'Cola eliminada correctamente', 'data' => true];
        } catch (\Throwable $e) {
            return ['status' => 1, 'message' => 'Error al eliminar cola: ' . $e->getMessage(), 'data' => null];
        }
    }

    public function getRouterConfig(?int $routerId = null): array
    {
        $companyId = getSessionCompanyId();

        if ($routerId) {
            $router = $this->routerRepositoryInterface->getRouterById($routerId, $companyId);
        } else {
            $router = $this->routerRepositoryInterface->getRouterByCompany($companyId);
        }

        if (!$router) {
            return ['status' => 0, 'message' => 'Sin configuración', 'data' => null];
        }

        return [
            'status'  => 0,
            'message' => 'Configuración obtenida',
            'data'    => [
                'id'   => $router->id,
                'name' => $router->name,
                'host' => $router->host,
                'user' => $router->user,
                'port' => $router->port ?? 8728,
            ],
        ];
    }

    public function saveRouterConfig(array $data): array
    {
        try {
            $companyId = getSessionCompanyId();
            $router    = $this->routerRepositoryInterface->saveRouterConfig($companyId, $data);
            return [
                'status'  => 0,
                'message' => 'Configuración guardada correctamente',
                'data'    => ['id' => $router->id, 'name' => $router->name, 'host' => $router->host, 'user' => $router->user, 'port' => $router->port],
            ];
        } catch (\Throwable $e) {
            return ['status' => 1, 'message' => 'Error al guardar: ' . $e->getMessage(), 'data' => null];
        }
    }

    public function suspendBulk(array $userIds, ?int $routerId = null): array
    {
        $results = [];
        $errors  = [];

        try {
            // 🔹 Si se pasó routerId explícito (desde el selector de Mikrotik page), usar ese
            if ($routerId) {
                $api = $this->conectionRouterManagerInterface->conection($this->resolveToken($routerId));
                foreach ($userIds as $userId) {
                    $this->suspendSingleUser($userId, $api, $results, $errors);
                }
                return [
                    'status'  => 0,
                    'message' => count($results) . ' cliente(s) suspendido(s)',
                    'data'    => ['suspended' => $results, 'errors' => $errors],
                ];
            }

            // 🔹 Multi-router: agrupar usuarios por router_id
            $usersData = UserData::whereIn('user_id', $userIds)
                ->join('users', 'users.id', '=', 'user_data.user_id')
                ->where('users.company_id', getSessionCompanyId())
                ->select('user_data.user_id', 'user_data.router_id')
                ->get()
                ->keyBy('user_id');

            $grouped = [];
            foreach ($userIds as $userId) {
                $uid = (int) $userId;
                $rId = $usersData[$uid]?->router_id ? (int) $usersData[$uid]->router_id : 0; // 0 = default router
                $grouped[$rId][] = $uid;
            }

            foreach ($grouped as $rId => $ids) {
                try {
                    $token = $this->resolveToken($rId === 0 ? null : $rId);
                    $api   = $this->conectionRouterManagerInterface->conection($token);

                    foreach ($ids as $uid) {
                        $this->suspendSingleUser($uid, $api, $results, $errors);
                    }
                } catch (\Throwable $routerErr) {
                    foreach ($ids as $uid) {
                        $errors[] = "User $uid: router error - " . $routerErr->getMessage();
                    }
                }
            }

            return [
                'status'  => 0,
                'message' => count($results) . ' cliente(s) suspendido(s)',
                'data'    => ['suspended' => $results, 'errors' => $errors],
            ];
        } catch (\Throwable $e) {
            return ['status' => 1, 'message' => 'Error de conexión: ' . $e->getMessage(), 'data' => null];
        }
    }

    private function suspendSingleUser(int $userId, $api, array &$results, array &$errors): void
    {
        try {
            $userData = UserData::where('user_data.user_id', $userId)
                ->join('users', 'users.id', '=', 'user_data.user_id')
                ->where('users.company_id', getSessionCompanyId())
                ->select('user_data.*')
                ->first();

            if (!$userData) { $errors[] = "User $userId: no encontrado"; return; }

            $dni = $userData->dni;

            $arpQuery = new Query('/ip/arp/print');
            $arpQuery->where('comment', $dni);
            $arpQuery->add('=.proplist=.id,address');
            $arpEntries = $api->query($arpQuery)->read();

            if (empty($arpEntries)) { $errors[] = "User $userId ($dni): sin ARP"; return; }

            foreach ($arpEntries as $arp) {
                $disableQuery = new Query('/ip/arp/disable');
                $disableQuery->equal('.id', $arp['.id']);
                $api->query($disableQuery)->read();

                $ip = $arp['address'] ?? '';
                if ($ip) {
                    $alQuery = new Query('/ip/firewall/address-list/add');
                    $alQuery->equal('list', 'morosos');
                    $alQuery->equal('address', $ip);
                    $alQuery->equal('comment', $dni);
                    $api->query($alQuery)->read();
                }
            }

            UserData::where('user_id', $userId)->update(['status_internet_id' => 2]);

            try {
                if ($userData->phone) {
                    $balance = $this->getPendingBalance($userId);
                    $wa      = new WhatsAppService();
                    $wa->mensajeInformativo($userData->phone, $this->resolveSuspensionTemplate($userData, $balance));
                }
            } catch (\Throwable $waErr) {
                error_log("WA suspension notify user $userId: " . $waErr->getMessage());
            }

            $results[] = $userId;
        } catch (\Throwable $inner) {
            $errors[] = "User $userId: " . $inner->getMessage();
        }
    }

    private function getPendingBalance(int $userId): float
    {
        $cab = CabFacturation::where('user_id', $userId)->first();
        if (!$cab) return 0.0;
        return (float) DetFacturation::where('cab_id', $cab->id)->where('paid', '<>', 1)->sum('price_total');
    }

    private function resolveSuspensionTemplate($userData, float $balance): string
    {
        $template = "🚫Estimado/a {nombre} {apellido}, le informamos que su servicio de internet ha sido *suspendido* por falta de pago.🚫\n\n"
                  . "💰 Saldo pendiente: *\${deuda}*\n\n"
                  . "Para reactivar su servicio, comuníquese con nosotros o realice su pago.\n\n"
                  . "📅 Fecha: {fecha}"
                  . "Medios de pago: BANCOLOMBIA CTA AHO 47800013328\nDAVIPLATA 3022042294\nNEQUI 3022042294";

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
}
