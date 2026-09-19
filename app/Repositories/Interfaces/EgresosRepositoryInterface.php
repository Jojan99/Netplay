<?php

namespace App\Repositories\Interfaces;

use App\Http\Requests\Egresos\CreateEgresosRequest;

/**
 * Clase interfaz encargada de administrar el repositorio de usuarios
 *
 * @package App\Repositories\Interfaces
 * @author Netplay <sa.networkgolden@gmail.com>
 * @copyright 2023/06/9
 */
interface EgresosRepositoryInterface
{

    /**
     * @return mixed
     */
    public function createEgresos(CreateEgresosRequest $data): mixed;
 
    /**
     * @return mixed
     */
    public function getEgresosAll(): mixed;


     /**
     * @return mixed
     */
    public function getPriceEgresseAll(): mixed;

    public function getPriceEgresseByRange(?string $from, ?string $to): mixed;

    public function getIngresosDetailed(?string $from, ?string $to): mixed;

    // ── Egresos v2 ──────────────────────────────────────────────────────────
    public function getEgresosPaginated(array $filtros): object;
    public function createEgresoV2(array $data): mixed;
    public function updateEgreso(int $id, array $data): bool;
    public function deleteEgreso(int $id): bool;
    public function exportEgresos(array $filtros): array;
    public function buscarEgreso(int $id): ?object;
    public function rutaAdjunto(int $id): ?object;
    public function repetirEgreso(int $id): array;

    // ── Tablero y categorías por empresa ────────────────────────────────────
    public function getTablero(array $filtros): array;
    public function getCategorias(): array;
    public function crearCategoria(string $nombre, ?string $color): array;
    public function actualizarCategoria(int $id, ?string $nombre, ?string $color): array;
    public function alternarCategoria(int $id): array;
    public function eliminarCategoria(int $id): array;
}
