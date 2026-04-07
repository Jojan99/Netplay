<?php

namespace App\UseCases\OltAdmin;

use App\Models\OltAdmin;
use App\OltDrivers\HuaweiOltDriver;
use App\OltDrivers\Interfaces\OltDriverInterface;
use App\Repositories\Interfaces\OltAdminRepositoryInterface;
use App\Services\OltConnectionFactory;
use App\Services\HuaweiSnmpReader;
use Illuminate\Support\Facades\Cache;

class OltAdminUseCase
{
    private array $connections = []; // Caché de conexiones abiertas

    public function __construct(
        private OltAdminRepositoryInterface $repo,
        private OltConnectionFactory        $factory,
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

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return ['status' => 0, 'message' => count($cached) . ' ONTs sin autenticar (caché)', 'data' => $cached];
        }

        try {
            $driver = $this->driver($oltId);
            $onts   = $driver->getUnauthONTs();
            Cache::put($cacheKey, $onts, now()->addMinutes(self::CACHE_TTL));
            return ['status' => 0, 'message' => count($onts) . ' ONTs sin autenticar', 'data' => $onts];
        } catch (\Throwable $e) {
            \Log::error('OLT getUnauthONTs error', ['olt_id' => $oltId, 'error' => $e->getMessage()]);
            $this->closeConnection($oltId);
            return ['status' => 1, 'message' => 'Error consultando OLT: ' . $e->getMessage(), 'data' => null];
        }
    }

    public function registerONT(int $oltId, array $data): array
    {
        try {
            $driver = $this->driver($oltId);
            $result = $driver->registerONT(
                fsp:         $data['fsp'],
                serial:      $data['serial'],
                description: $data['description'] ?? $data['serial'],
            );

            if ($result['success']) {
                Cache::forget("olt:{$oltId}:unauth_onts");
            }

            $msg = $result['success']
                ? "ONT registrada — Port {$result['port_id']}, ONTID {$result['ont_id']}"
                : 'Error al registrar ONT';

            return ['status' => $result['success'] ? 0 : 1, 'message' => $msg, 'data' => $result];
        } catch (\Throwable $e) {
            \Log::error('OLT registerONT error', ['olt_id' => $oltId, 'error' => $e->getMessage()]);
            $this->closeConnection($oltId);
            return ['status' => 1, 'message' => 'Error registrando ONT: ' . $e->getMessage(), 'data' => null];
        }
    }

    public function deleteONT(int $oltId, array $data): array
    {
        try {
            $driver = $this->driver($oltId);
            $ok     = $driver->deleteONT(
                fsp:         $data['fsp'],
                ontId:       (int) $data['ont_id'],
                servicePort: (int) ($data['service_port'] ?? 0),
            );

            if ($ok) {
                Cache::forget("olt:{$oltId}:unauth_onts");
            }

            return [
                'status'  => $ok ? 0 : 1,
                'message' => $ok ? 'ONT eliminada correctamente' : 'Error al eliminar ONT',
                'data'    => null,
            ];
        } catch (\Throwable $e) {
            \Log::error('OLT deleteONT error', ['olt_id' => $oltId, 'error' => $e->getMessage()]);
            $this->closeConnection($oltId);
            return ['status' => 1, 'message' => 'Error eliminando ONT: ' . $e->getMessage(), 'data' => null];
        }
    }

    public function assignONTToClient(int $oltId, array $data): array
    {
        try {
            $oltModel = $this->getOltModel($oltId);
            $driver   = $this->driver($oltId);

            $ok = $driver->assignToClient(
                fsp:         $data['fsp'],
                ontId:       (int) $data['ont_id'],
                vlan:        (int) ($data['vlan'] ?? $oltModel->default_vlan),
                servicePort: (int) $data['service_port'],
                description: $data['description'] ?? '',
            );

            return [
                'status'  => $ok ? 0 : 1,
                'message' => $ok ? 'Service-port creado, ONT asignada al cliente' : 'Error al asignar ONT',
                'data'    => null,
            ];
        } catch (\Throwable $e) {
            \Log::error('OLT assignONT error', ['olt_id' => $oltId, 'error' => $e->getMessage()]);
            $this->closeConnection($oltId);
            return ['status' => 1, 'message' => 'Error asignando ONT: ' . $e->getMessage(), 'data' => null];
        }
    }

    /**
     * Get all authorized/registered ONTs via SNMP (fast, no Telnet session).
     * Cached for 2 minutes.
     */
    public function getAuthorizedONTs(int $oltId): array
    {
        $cacheKey = "olt:{$oltId}:auth_onts";

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return ['status' => 0, 'message' => count($cached) . ' ONTs autorizadas (caché)', 'data' => $cached];
        }

        try {
            $olt    = $this->getOltModel($oltId);
            $reader = $this->snmpReader($olt);
            $onts   = $reader->getAuthorizedONTs();
            Cache::put($cacheKey, $onts, now()->addMinutes(2));
            return ['status' => 0, 'message' => count($onts) . ' ONTs autorizadas', 'data' => $onts];
        } catch (\Throwable $e) {
            \Log::error('OLT getAuthorizedONTs SNMP error', ['olt_id' => $oltId, 'error' => $e->getMessage()]);
            return ['status' => 1, 'message' => 'Error SNMP: ' . $e->getMessage(), 'data' => null];
        }
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
            \Log::error('OLT getOntInfo SNMP error', ['olt_id' => $oltId, 'fsp' => $fsp, 'ont_id' => $ontId, 'error' => $e->getMessage()]);
            return ['status' => 1, 'message' => 'Error SNMP: ' . $e->getMessage(), 'data' => null];
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
            $driver = $this->driver($oltId);
            $ports  = $driver->getServicePorts($fsp, $ontId);
            Cache::put($cacheKey, $ports, now()->addMinutes(2));
            return ['status' => 0, 'message' => count($ports) . ' service-ports', 'data' => $ports];
        } catch (\Throwable $e) {
            \Log::error('OLT getServicePorts error', ['olt_id' => $oltId, 'error' => $e->getMessage()]);
            $this->closeConnection($oltId);
            return ['status' => 1, 'message' => 'Error obteniendo service-ports: ' . $e->getMessage(), 'data' => null];
        }
    }

    /**
     * Transfer an ONT from one port to another. Invalidates auth_onts cache.
     */
    public function transferONT(int $oltId, array $data): array
    {
        try {
            $driver = $this->driver($oltId);
            $result = $driver->transferONT(
                fromFsp: $data['from_fsp'],
                ontId:   (int) $data['ont_id'],
                toFsp:   $data['to_fsp'],
            );

            if ($result['success']) {
                Cache::forget("olt:{$oltId}:auth_onts");
                Cache::forget("olt:{$oltId}:unauth_onts");
            }

            return [
                'status'  => $result['success'] ? 0 : 1,
                'message' => $result['message'],
                'data'    => $result,
            ];
        } catch (\Throwable $e) {
            \Log::error('OLT transferONT error', ['olt_id' => $oltId, 'error' => $e->getMessage()]);
            $this->closeConnection($oltId);
            return ['status' => 1, 'message' => 'Error transfiriendo ONT: ' . $e->getMessage(), 'data' => null];
        }
    }

    /**
     * Deactivate an ONT. Invalidates auth_onts cache.
     */
    public function deactivateONT(int $oltId, array $data): array
    {
        try {
            $driver = $this->driver($oltId);
            $ok     = $driver->deactivateONT(
                fsp:   $data['fsp'],
                ontId: (int) $data['ont_id'],
            );

            if ($ok) {
                Cache::forget("olt:{$oltId}:auth_onts");
            }

            return [
                'status'  => $ok ? 0 : 1,
                'message' => $ok ? 'ONT desactivada correctamente' : 'Error al desactivar ONT',
                'data'    => null,
            ];
        } catch (\Throwable $e) {
            \Log::error('OLT deactivateONT error', ['olt_id' => $oltId, 'error' => $e->getMessage()]);
            $this->closeConnection($oltId);
            return ['status' => 1, 'message' => 'Error desactivando ONT: ' . $e->getMessage(), 'data' => null];
        }
    }

    /**
     * Activate an ONT. Invalidates auth_onts cache.
     */
    public function activateONT(int $oltId, array $data): array
    {
        try {
            $driver = $this->driver($oltId);
            $ok     = $driver->activateONT(
                fsp:   $data['fsp'],
                ontId: (int) $data['ont_id'],
            );

            if ($ok) {
                Cache::forget("olt:{$oltId}:auth_onts");
            }

            return [
                'status'  => $ok ? 0 : 1,
                'message' => $ok ? 'ONT activada correctamente' : 'Error al activar ONT',
                'data'    => null,
            ];
        } catch (\Throwable $e) {
            \Log::error('OLT activateONT error', ['olt_id' => $oltId, 'error' => $e->getMessage()]);
            $this->closeConnection($oltId);
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

    /**
     * Obtiene una conexión reutilizando caché si existe
     */
    private function driver(int $oltId): OltDriverInterface
    {
        $olt = $this->getOltModel($oltId);

        // Si ya tenemos una conexión abierta, reutilizarla
        if (isset($this->connections[$oltId])) {
            \Log::info('OLT CONNECTION REUSED', ['olt_id' => $oltId]);
            $ssh = $this->connections[$oltId];
        } else {
            // Crear nueva conexión
            \Log::info('OLT CONNECTION CREATED', ['olt_id' => $oltId]);
            $ssh = $this->factory->connect($olt);
            $this->connections[$oltId] = $ssh;
        }

        return match ($olt->brand) {
            'huawei' => new HuaweiOltDriver($ssh, $olt->toArray()),
            default  => throw new \RuntimeException("Driver para marca '{$olt->brand}' no implementado"),
        };
    }

    /**
     * Cierra una conexión abierta
     */
    private function closeConnection(int $oltId): void
    {
        if (isset($this->connections[$oltId])) {
            \Log::info('OLT CONNECTION CLOSED', ['olt_id' => $oltId]);
            unset($this->connections[$oltId]);
        }
    }

    /**
     * Cierra todas las conexiones abiertas
     */
    public function closeAllConnections(): void
    {
        foreach (array_keys($this->connections) as $oltId) {
            $this->closeConnection($oltId);
        }
    }

    /**
     * Build the right SNMP reader for the OLT brand.
     * Only Huawei is supported for now.
     */
    private function snmpReader(OltAdmin $olt): HuaweiSnmpReader
    {
        return new HuaweiSnmpReader($olt);
    }

    /**
     * Destructor para limpiar conexiones
     */
    public function __destruct()
    {
        $this->closeAllConnections();
    }
}
