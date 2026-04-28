<?php

namespace App\UseCases\OltAdmin;

use App\Models\OltAdmin;
use App\Models\OltOnt;
use App\Models\OltProfile;
use App\Repositories\Interfaces\OltAdminRepositoryInterface;
use App\Services\HuaweiSnmpReader;
use App\Services\OltTelnetDispatcher;
use Illuminate\Support\Facades\Cache;

class OltAdminUseCase
{
    public function __construct(
        private OltAdminRepositoryInterface $repo,
        private OltTelnetDispatcher         $dispatcher,
    ) {}

    // ── CRUD ──────────────────────────────────────────────────────────────

    public function listOlts(): array
    {
        return ['status' => 0, 'message' => 'OK', 'data' => $this->repo->getAllByCompany()];
    }

    public function createOlt(array $data): array
    {
        $olt = $this->repo->create($data);
        return ['status' => 0, 'message' => 'OLT creada correctamente', 'data' => $olt];
    }

    public function updateOlt(int $id, array $data): array
    {
        if (!$this->repo->findById($id)) {
            return ['status' => 1, 'message' => 'OLT no encontrada', 'data' => null];
        }
        $this->repo->update($id, $data);
        return ['status' => 0, 'message' => 'OLT actualizada', 'data' => null];
    }

    public function deleteOlt(int $id): array
    {
        if (!$this->repo->findById($id)) {
            return ['status' => 1, 'message' => 'OLT no encontrada', 'data' => null];
        }
        $this->repo->delete($id);
        $this->closeConnection($id);
        return ['status' => 0, 'message' => 'OLT eliminada', 'data' => null];
    }

    // ── ONT operations ────────────────────────────────────────────────────

    // Minutos que se cachean los resultados de consulta al OLT
    private const CACHE_TTL = 3;

    public function getUnauthONTs(int $oltId): array
    {
        $cacheKey = "olt:{$oltId}:unauth_onts";

        // $cached = Cache::get($cacheKey);
        // if ($cached !== null) {
        //     return ['status' => 0, 'message' => count($cached) . ' ONTs sin autenticar (caché)', 'data' => $cached];
        // }

        // SNMP first — fast, no Telnet session needed
        try {
            $olt  = $this->getOltModel($oltId);
            $onts = $this->snmpReader($olt)->getUnauthONTs();

            if (!empty($onts)) {
                Cache::put($cacheKey, $onts, now()->addMinutes(self::CACHE_TTL));
                return ['status' => 0, 'message' => count($onts) . ' ONTs sin autenticar (SNMP)', 'data' => $onts];
            }

            \Log::info('OLT getUnauthONTs SNMP vacío, usando Telnet', ['olt_id' => $oltId]);
        } catch (\Throwable $e) {
            \Log::warning('OLT getUnauthONTs SNMP falló, usando Telnet', ['olt_id' => $oltId, 'error' => $e->getMessage()]);
        }

        // Telnet fallback
        try {
            $onts = $this->dispatcher->dispatch($oltId, 'getUnauthONTs');
            Cache::put($cacheKey, $onts, now()->addMinutes(self::CACHE_TTL));
            return ['status' => 0, 'message' => count($onts) . ' ONTs sin autenticar (Telnet)', 'data' => $onts];
        } catch (\Throwable $e) {
            \Log::error('OLT getUnauthONTs Telnet error', ['olt_id' => $oltId, 'error' => $e->getMessage()]);
            return ['status' => 1, 'message' => 'Error consultando OLT: ' . $e->getMessage(), 'data' => null];
        }
    }

    public function syncProfiles(int $oltId): array
    {
        try {
            $lineProfiles = $this->dispatcher->dispatch($oltId, 'getLineProfiles');
            $srvProfiles  = $this->dispatcher->dispatch($oltId, 'getSrvProfiles');
            $now = now();

            foreach ($lineProfiles as $p) {
                OltProfile::updateOrCreate(
                    ['olt_id' => $oltId, 'type' => 'line', 'profile_id' => $p['id']],
                    ['profile_name' => $p['name'], 'synced_at' => $now]
                );
            }
            foreach ($srvProfiles as $p) {
                OltProfile::updateOrCreate(
                    ['olt_id' => $oltId, 'type' => 'srv', 'profile_id' => $p['id']],
                    ['profile_name' => $p['name'], 'synced_at' => $now]
                );
            }

            return ['status' => 0, 'message' => 'Perfiles sincronizados', 'data' => [
                'line' => count($lineProfiles),
                'srv'  => count($srvProfiles),
                'synced_at' => $now->toIso8601String(),
            ]];
        } catch (\Throwable $e) {
            \Log::error('OLT syncProfiles error', ['olt_id' => $oltId, 'error' => $e->getMessage()]);
            return ['status' => 1, 'message' => 'Error sincronizando perfiles: ' . $e->getMessage(), 'data' => null];
        }
    }

    public function getProfiles(int $oltId): array
    {
        $line = OltProfile::where('olt_id', $oltId)->where('type', 'line')
            ->orderBy('profile_id')
            ->get(['profile_id', 'profile_name', 'synced_at']);

        $srv = OltProfile::where('olt_id', $oltId)->where('type', 'srv')
            ->orderBy('profile_id')
            ->get(['profile_id', 'profile_name', 'synced_at']);

        return ['status' => 0, 'message' => 'OK', 'data' => ['line' => $line, 'srv' => $srv]];
    }

    public function registerONT(int $oltId, array $data): array
    {
        try {
            $desc = strtoupper(str_replace(' ', '_', trim($data['description'] ?? $data['serial'])));

            $vlan    = !empty($data['vlan']) ? (int) $data['vlan'] : null;
            $spIndex = $vlan !== null ? $this->generateServicePort($oltId, $vlan) : null;

            \Log::debug('OLT registerONT: payload recibido', [
                'olt_id'       => $oltId,
                'fsp'          => $data['fsp'],
                'vlan_raw'     => $data['vlan'] ?? 'NO VIENE',
                'vlan_parsed'  => $vlan,
                'sp_index'     => $spIndex,
            ]);

            $result = $this->dispatcher->dispatch($oltId, 'registerONT', [
                'fsp'             => $data['fsp'],
                'serial'          => $data['serial'],
                'description'     => $desc,
                'line_profile_id' => isset($data['line_profile_id']) ? (int) $data['line_profile_id'] : null,
                'srv_profile_id'  => isset($data['srv_profile_id'])  ? (int) $data['srv_profile_id']  : null,
                'vlan'            => $vlan,
                'service_port'    => $spIndex,
            ]);

            if ($result['success']) {
                Cache::forget("olt:{$oltId}:unauth_onts");

                $ontId     = (int) $result['ont_id'];
                $spCreated = $result['service_port_created'] ?? false;

                OltOnt::updateOrCreate(
                    ['olt_id' => $oltId, 'fsp' => $data['fsp'], 'ont_id' => $ontId],
                    [
                        'serial'        => $data['serial'],
                        'description'   => $desc,
                        'status'        => 'offline',
                        'service_ports' => ($spCreated && $vlan !== null && $spIndex !== null)
                                            ? [['index' => $spIndex, 'vlan' => $vlan]]
                                            : [],
                        'synced_at'     => now(),
                    ]
                );

                Cache::forget("olt:{$oltId}:all_service_ports");
            }

            $msg = $result['success']
                ? "ONT registrada — Port {$result['port_id']}, ONTID {$result['ont_id']}"
                : 'Error al registrar ONT';

            return ['status' => $result['success'] ? 0 : 1, 'message' => $msg, 'data' => $result];
        } catch (\Throwable $e) {
            \Log::error('OLT registerONT error', ['olt_id' => $oltId, 'error' => $e->getMessage()]);
            return ['status' => 1, 'message' => 'Error registrando ONT: ' . $e->getMessage(), 'data' => null];
        }
    }

    public function cliCommand(int $oltId, string $command): array
    {
        try {
            $output = $this->dispatcher->dispatch($oltId, 'runCommand', ['command' => $command]);
            return ['status' => 0, 'message' => 'OK', 'data' => ['output' => $output, 'command' => $command]];
        } catch (\Throwable $e) {
            return ['status' => 1, 'message' => $e->getMessage(), 'data' => null];
        }
    }

    public function deleteONT(int $oltId, array $data): array
    {
        try {
            $fsp   = $data['fsp'];
            $ontId = (int) $data['ont_id'];

            // Auto-obtener todos los service-ports de esta ONT para eliminarlos primero
            $servicePortIndices = [];
            try {
                $ports = $this->dispatcher->dispatch($oltId, 'getServicePorts', [
                    'fsp'    => $fsp,
                    'ont_id' => $ontId,
                ]);
                $servicePortIndices = array_filter(array_column($ports, 'index'));
                Cache::forget("olt:{$oltId}:service_ports:{$fsp}:{$ontId}");
            } catch (\Throwable $e) {
                // Si es un error de sesión (límite, timeout) lo relanzamos — no tiene sentido continuar
                if (str_contains($e->getMessage(), 'session limit') || str_contains($e->getMessage(), 'Timeout') || str_contains($e->getMessage(), 'Reenter')) {
                    throw $e;
                }
                \Log::warning('OLT deleteONT: no se pudieron obtener service-ports, se continúa sin ellos', [
                    'olt_id' => $oltId, 'fsp' => $fsp, 'ont_id' => $ontId, 'error' => $e->getMessage(),
                ]);
            }

            $ok = $this->dispatcher->dispatch($oltId, 'deleteONT', [
                'fsp'           => $fsp,
                'ont_id'        => $ontId,
                'service_ports' => array_values($servicePortIndices),
            ]);

            if ($ok) {
                Cache::forget("olt:{$oltId}:unauth_onts");
                Cache::forget("olt:{$oltId}:all_service_ports");
                OltOnt::where('olt_id', $oltId)
                    ->where('fsp', $fsp)
                    ->where('ont_id', $ontId)
                    ->delete();
            }

            return [
                'status'  => $ok ? 0 : 1,
                'message' => $ok ? 'ONT eliminada correctamente' : 'Error al eliminar ONT',
                'data'    => null,
            ];
        } catch (\Throwable $e) {
            \Log::error('OLT deleteONT error', ['olt_id' => $oltId, 'error' => $e->getMessage()]);
            return ['status' => 1, 'message' => 'Error eliminando ONT: ' . $e->getMessage(), 'data' => null];
        }
    }

    public function assignONTToClient(int $oltId, array $data): array
    {
        try {
            $oltModel = $this->getOltModel($oltId);

            $ok = $this->dispatcher->dispatch($oltId, 'assignToClient', [
                'fsp'         => $data['fsp'],
                'ont_id'      => (int) $data['ont_id'],
                'vlan'        => (int) ($data['vlan'] ?? $oltModel->default_vlan),
                'service_port'=> (int) $data['service_port'],
                'description' => $data['description'] ?? '',
            ]);

            return [
                'status'  => $ok ? 0 : 1,
                'message' => $ok ? 'Service-port creado, ONT asignada al cliente' : 'Error al asignar ONT',
                'data'    => null,
            ];
        } catch (\Throwable $e) {
            \Log::error('OLT assignONT error', ['olt_id' => $oltId, 'error' => $e->getMessage()]);
            return ['status' => 1, 'message' => 'Error asignando ONT: ' . $e->getMessage(), 'data' => null];
        }
    }

    /**
     * Get all authorized ONTs. Reads from DB first.
     * Only queries the OLT (SNMP → Telnet) when the DB is empty.
     * Pass ?force=1 to skip the DB and re-sync from the OLT.
     */
    public function getAuthorizedONTs(int $oltId, bool $force = false): array
    {
        if (!$force) {
            $dbOnts = OltOnt::where('olt_id', $oltId)
                            ->with('client:id,user_id,names,lastname,dni')
                            ->orderBy('fsp')->orderBy('ont_id')
                            ->get()
                            ->map(fn($o) => array_merge($o->toArray(), ['assigned_client' => $o->client]))
                            ->toArray();
            if (!empty($dbOnts)) {
                return ['status' => 0, 'message' => count($dbOnts) . ' ONTs autorizadas (BD)', 'data' => $dbOnts];
            }
        }

        // BD vacía o force → consultar OLT y guardar
        try {
            $olt    = $this->getOltModel($oltId);
            $reader = $this->snmpReader($olt);
            $onts   = $reader->getAuthorizedONTs();

            if (empty($onts)) {
                \Log::info('OLT getAuthorizedONTs SNMP returned empty, falling back to Telnet driver', ['olt_id' => $oltId]);
                $onts = $this->dispatcher->dispatch($oltId, 'getAuthorizedONTs');
            }

            $onts = $this->mergeServicePorts($oltId, $onts);
            $this->syncONTsToDb($oltId, $onts);

            return ['status' => 0, 'message' => count($onts) . ' ONTs autorizadas', 'data' => $onts];
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'SNMP_JUMP_UNAVAILABLE')) {
                try {
                    $onts = $this->dispatcher->dispatch($oltId, 'getAuthorizedONTs');
                    $onts = $this->mergeServicePorts($oltId, $onts);
                    $this->syncONTsToDb($oltId, $onts);
                    return ['status' => 0, 'message' => count($onts) . ' ONTs autorizadas (Telnet)', 'data' => $onts];
                } catch (\Throwable $e2) {
                    \Log::error('OLT getAuthorizedONTs Telnet fallback error', ['olt_id' => $oltId, 'error' => $e2->getMessage()]);
                    return ['status' => 1, 'message' => 'Error: ' . $e2->getMessage(), 'data' => null];
                }
            }

            \Log::error('OLT getAuthorizedONTs error', ['olt_id' => $oltId, 'error' => $e->getMessage()]);
            return ['status' => 1, 'message' => 'Error SNMP: ' . $e->getMessage(), 'data' => null];
        }
    }

    /**
     * Persiste el snapshot de ONTs en olt_onts.
     * Hace upsert por (olt_id, fsp, ont_id) y elimina las que ya no existen.
     */
    private function syncONTsToDb(int $oltId, array $onts): void
    {
        $now  = now();
        $keys = [];

        foreach ($onts as $ont) {
            OltOnt::updateOrCreate(
                ['olt_id' => $oltId, 'fsp' => $ont['fsp'], 'ont_id' => $ont['ont_id']],
                [
                    'serial'        => $ont['serial']        ?? null,
                    'description'   => $ont['description']   ?? null,
                    'status'        => $ont['status']        ?? 'offline',
                    'service_ports' => $ont['service_ports'] ?? [],
                    'synced_at'     => $now,
                ]
            );
            $keys[] = $ont['fsp'] . ':' . $ont['ont_id'];
        }

        // Eliminar de BD las ONTs que ya no existen en la OLT
        OltOnt::where('olt_id', $oltId)
            ->get(['id', 'fsp', 'ont_id'])
            ->each(function ($row) use ($keys) {
                if (!in_array($row->fsp . ':' . $row->ont_id, $keys)) {
                    $row->delete();
                }
            });
    }

    /**
     * Get detailed info + optical power for a single ONT via SNMP.
     * Cached for 1 minute.
     */
    public function getOntInfo(int $oltId, string $fsp, int $ontId): array
    {
        $cacheKey = "olt:{$oltId}:ont_info:{$fsp}:{$ontId}";

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return ['status' => 0, 'message' => 'Info ONT (caché)', 'data' => $cached];
        }

        try {
            $olt    = $this->getOltModel($oltId);
            $reader = $this->snmpReader($olt);
            $info   = $reader->getOntInfo($fsp, $ontId);
            Cache::put($cacheKey, $info, now()->addMinutes(1));
            return ['status' => 0, 'message' => 'Info ONT obtenida', 'data' => $info];
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'SNMP_JUMP_UNAVAILABLE')) {
                try {
                    $info = $this->dispatcher->dispatch($oltId, 'getOntInfo', ['fsp' => $fsp, 'ont_id' => $ontId]);
                    Cache::put($cacheKey, $info, now()->addMinutes(1));
                    return ['status' => 0, 'message' => 'Info ONT obtenida (Telnet)', 'data' => $info];
                } catch (\Throwable $e2) {
                    return ['status' => 1, 'message' => 'Error: ' . $e2->getMessage(), 'data' => null];
                }
            }
            \Log::error('OLT getOntInfo error', ['olt_id' => $oltId, 'fsp' => $fsp, 'ont_id' => $ontId, 'error' => $e->getMessage()]);
            return ['status' => 1, 'message' => 'Error: ' . $e->getMessage(), 'data' => null];
        }
    }

    /**
     * Get service ports for a specific ONT via Telnet (targeted query, fast).
     * fsp and ontId are required — "display all" is too slow over Telnet.
     */
    public function getServicePorts(int $oltId, ?string $fsp, ?int $ontId): array
    {
        if ($fsp === null || $ontId === null) {
            return ['status' => 1, 'message' => 'Selecciona una ONT para ver sus service-ports.', 'data' => []];
        }

        $cacheKey = "olt:{$oltId}:service_ports:{$fsp}:{$ontId}";

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return ['status' => 0, 'message' => count($cached) . ' service-ports (caché)', 'data' => $cached];
        }

        try {
            $ports = $this->dispatcher->dispatch($oltId, 'getServicePorts', ['fsp' => $fsp, 'ont_id' => $ontId]);
            Cache::put($cacheKey, $ports, now()->addMinutes(2));
            return ['status' => 0, 'message' => count($ports) . ' service-ports', 'data' => $ports];
        } catch (\Throwable $e) {
            \Log::error('OLT getServicePorts error', ['olt_id' => $oltId, 'error' => $e->getMessage()]);
            return ['status' => 1, 'message' => 'Error obteniendo service-ports: ' . $e->getMessage(), 'data' => null];
        }
    }

    /**
     * Auto-assign a service port to an ONT.
     * El número se genera con HHMMSS (6 dígitos, siempre único por segundo).
     * Si ya está en uso, incrementa hasta encontrar uno libre en la BD.
     */
    public function autoAssignONT(int $oltId, array $data): array
    {
        try {
            $oltModel = $this->getOltModel($oltId);
            $vlan     = (int) ($data['vlan'] ?? $oltModel->default_vlan);
            $nextSp   = $this->generateServicePort($oltId, $vlan);

            $ok = $this->dispatcher->dispatch($oltId, 'assignToClient', [
                'fsp'          => $data['fsp'],
                'ont_id'       => (int) $data['ont_id'],
                'vlan'         => $vlan,
                'service_port' => $nextSp,
                'description'  => $data['description'] ?? '',
            ]);

            if ($ok) {
                Cache::forget("olt:{$oltId}:auth_onts");
                Cache::forget("olt:{$oltId}:all_service_ports");
                Cache::forget("olt:{$oltId}:service_ports:{$data['fsp']}:{$data['ont_id']}");
                $dbOnt = OltOnt::where('olt_id', $oltId)
                    ->where('fsp', $data['fsp'])
                    ->where('ont_id', (int) $data['ont_id'])
                    ->first();
                if ($dbOnt) {
                    $ports   = $dbOnt->service_ports ?? [];
                    $ports[] = ['index' => $nextSp, 'vlan' => (int) ($data['vlan'] ?? $oltModel->default_vlan)];
                    $dbOnt->update(['service_ports' => $ports, 'synced_at' => now()]);
                }
            }

            return [
                'status'  => $ok ? 0 : 1,
                'message' => $ok ? "Service-port {$nextSp} asignado correctamente" : 'Error al asignar ONT',
                'data'    => $ok ? ['service_port' => $nextSp] : null,
            ];
        } catch (\Throwable $e) {
            \Log::error('OLT autoAssignONT error', ['olt_id' => $oltId, 'error' => $e->getMessage()]);
            return ['status' => 1, 'message' => 'Error: ' . $e->getMessage(), 'data' => null];
        }
    }

    /**
     * Genera un número de service-port de 4 dígitos.
     * Composición: [último dígito año][último dígito día][último dígito minuto][último dígito segundo]
     * Ejemplo: año 2026, día 16, minuto 32, segundo 45 → 6·6·2·5 = 6625
     * Si ya está en uso, incrementa hasta encontrar uno libre (máx 9999).
     */
    private function generateServicePort(int $oltId, int $vlan = 0): int
    {
        $used = OltOnt::where('olt_id', $oltId)
            ->whereNotNull('service_ports')
            ->get('service_ports')
            ->flatMap(fn($o) => collect($o->service_ports)->pluck('index'))
            ->filter()
            ->values()
            ->all();

        $d1 = (int) date('Y') % 10; // último dígito del año
        $d2 = (int) date('j') % 10; // último dígito del día
        $d3 = (int) date('i') % 10; // último dígito del minuto
        $d4 = (int) date('s') % 10; // último dígito del segundo

        $candidate = (int) "{$d1}{$d2}{$d3}{$d4}";

        while (in_array($candidate, $used)) {
            $candidate = ($candidate % 9999) + 1;
        }

        return $candidate;
    }

    /**
     * Transfer an ONT from one port to another. Invalidates auth_onts cache.
     */
    public function transferONT(int $oltId, array $data): array
    {
        try {
            $result = $this->dispatcher->dispatch($oltId, 'transferONT', [
                'from_fsp' => $data['from_fsp'],
                'ont_id'   => (int) $data['ont_id'],
                'to_fsp'   => $data['to_fsp'],
            ]);

            if ($result['success']) {
                Cache::forget("olt:{$oltId}:auth_onts");
                Cache::forget("olt:{$oltId}:unauth_onts");
                // Mover el registro en BD al nuevo fsp sin re-sync completo
                OltOnt::where('olt_id', $oltId)
                    ->where('fsp', $data['from_fsp'])
                    ->where('ont_id', (int) $data['ont_id'])
                    ->update(['fsp' => $data['to_fsp'], 'service_ports' => [], 'synced_at' => now()]);
            }

            return [
                'status'  => $result['success'] ? 0 : 1,
                'message' => $result['message'],
                'data'    => $result,
            ];
        } catch (\Throwable $e) {
            \Log::error('OLT transferONT error', ['olt_id' => $oltId, 'error' => $e->getMessage()]);
            return ['status' => 1, 'message' => 'Error transfiriendo ONT: ' . $e->getMessage(), 'data' => null];
        }
    }

    /**
     * Deactivate an ONT. Invalidates auth_onts cache.
     */
    public function deactivateONT(int $oltId, array $data): array
    {
        try {
            $ok = $this->dispatcher->dispatch($oltId, 'deactivateONT', [
                'fsp'    => $data['fsp'],
                'ont_id' => (int) $data['ont_id'],
            ]);

            if ($ok) {
                Cache::forget("olt:{$oltId}:auth_onts");
                OltOnt::where('olt_id', $oltId)
                    ->where('fsp', $data['fsp'])
                    ->where('ont_id', (int) $data['ont_id'])
                    ->update(['status' => 'offline', 'synced_at' => now()]);
            }

            return [
                'status'  => $ok ? 0 : 1,
                'message' => $ok ? 'ONT desactivada correctamente' : 'Error al desactivar ONT',
                'data'    => null,
            ];
        } catch (\Throwable $e) {
            \Log::error('OLT deactivateONT error', ['olt_id' => $oltId, 'error' => $e->getMessage()]);
            return ['status' => 1, 'message' => 'Error desactivando ONT: ' . $e->getMessage(), 'data' => null];
        }
    }

    /**
     * Activate an ONT. Invalidates auth_onts cache.
     */
    public function activateONT(int $oltId, array $data): array
    {
        try {
            $ok = $this->dispatcher->dispatch($oltId, 'activateONT', [
                'fsp'    => $data['fsp'],
                'ont_id' => (int) $data['ont_id'],
            ]);

            if ($ok) {
                Cache::forget("olt:{$oltId}:auth_onts");
                OltOnt::where('olt_id', $oltId)
                    ->where('fsp', $data['fsp'])
                    ->where('ont_id', (int) $data['ont_id'])
                    ->update(['status' => 'online', 'synced_at' => now()]);
            }

            return [
                'status'  => $ok ? 0 : 1,
                'message' => $ok ? 'ONT activada correctamente' : 'Error al activar ONT',
                'data'    => null,
            ];
        } catch (\Throwable $e) {
            \Log::error('OLT activateONT error', ['olt_id' => $oltId, 'error' => $e->getMessage()]);
            return ['status' => 1, 'message' => 'Error activando ONT: ' . $e->getMessage(), 'data' => null];
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function getOltModel(int $id): OltAdmin
    {
        $row = $this->repo->findById($id);
        if (!$row) throw new \RuntimeException("OLT {$id} no encontrada");
        return OltAdmin::find($id);
    }

    private function snmpReader(OltAdmin $olt): HuaweiSnmpReader
    {
        return new HuaweiSnmpReader($olt);
    }

    /**
     * Obtiene todos los service-ports del OLT con un solo comando Telnet
     * y los añade a cada ONT como 'service_ports' => [['index'=>100,'vlan'=>200], ...].
     * Se cachea separado para no invalidar el listado SNMP cada vez.
     */
    private function mergeServicePorts(int $oltId, array $onts): array
    {
        $cacheKey = "olt:{$oltId}:all_service_ports";

        try {
            $allPorts = Cache::get($cacheKey);

            if ($allPorts === null) {
                $allPorts = $this->dispatcher->dispatch($oltId, 'getServicePorts');
                Cache::put($cacheKey, $allPorts, now()->addMinutes(5));
            }

            // Construir mapa fsp:ont_id → [service_ports]
            $map = [];
            foreach ($allPorts as $sp) {
                $key = ($sp['fsp'] ?? '') . ':' . ($sp['ont_id'] ?? '');
                $map[$key][] = ['index' => $sp['index'] ?? null, 'vlan' => $sp['vlan'] ?? null];
            }

            foreach ($onts as &$ont) {
                $key = ($ont['fsp'] ?? '') . ':' . ($ont['ont_id'] ?? '');
                $ont['service_ports'] = $map[$key] ?? [];
            }
            unset($ont);

        } catch (\Throwable $e) {
            // Si Telnet falla, devolvemos los ONTs sin service_ports (no bloqueante)
            \Log::warning('mergeServicePorts failed, skipping', ['olt_id' => $oltId, 'error' => $e->getMessage()]);
            foreach ($onts as &$ont) { $ont['service_ports'] = []; }
            unset($ont);
        }

        return $onts;
    }

    /**
     * Asignar (o desasignar) un cliente a una ONT registrada.
     * user_data_id = null → desasignar.
     */
    public function assignClientToOnt(int $oltId, string $fsp, int $ontId, ?int $userDataId): array
    {
        $ont = OltOnt::where('olt_id', $oltId)
                     ->where('fsp', $fsp)
                     ->where('ont_id', $ontId)
                     ->first();

        if (!$ont) {
            return ['status' => 1, 'message' => 'ONT no encontrada en la base de datos.', 'data' => null];
        }

        $ont->update(['user_data_id' => $userDataId]);

        return ['status' => 0, 'message' => $userDataId ? 'Cliente asignado correctamente.' : 'Cliente desasignado.', 'data' => $ont->fresh()];
    }

    /**
     * Obtener el equipo ONT asignado a un cliente.
     */
    public function getOntByUserId(int $userDataId): array
    {
        $ont = OltOnt::where('user_data_id', $userDataId)
                     ->with('olt:id,name,host')
                     ->first();

        if (!$ont) {
            return ['status' => 0, 'message' => 'Sin equipo ONT asignado.', 'data' => null];
        }

        return ['status' => 0, 'message' => 'Equipo ONT encontrado.', 'data' => $ont];
    }
}
