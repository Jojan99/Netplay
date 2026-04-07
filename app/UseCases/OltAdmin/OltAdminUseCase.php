<?php

namespace App\UseCases\OltAdmin;

use App\Models\OltAdmin;
use App\OltDrivers\HuaweiOltDriver;
use App\OltDrivers\Interfaces\OltDriverInterface;
use App\Repositories\Interfaces\OltAdminRepositoryInterface;
use App\Services\OltConnectionFactory;
use App\Constants\ProfileConstants;

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

    public function getUnauthONTs(int $oltId): array
    {
        try {
            $driver = $this->driver($oltId);
            $onts   = $driver->getUnauthONTs();
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
     * Destructor para limpiar conexiones
     */
    public function __destruct()
    {
        $this->closeAllConnections();
    }
}
