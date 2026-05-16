<?php

namespace App\Http\Controllers;

use App\Http\Requests\Inventory\CreateInventoryCategoryRequest;
use App\Http\Requests\Inventory\CreateInventoryMovementRequest;
use App\Http\Requests\Inventory\CreateInventoryRequest;
use App\Http\Requests\Inventory\UpdateInventoryCategoryRequest;
use App\Http\Requests\Inventory\UpdateInventoryRequest;
use App\UseCases\Inventory\Interfaces\{
    CreateInventoryCategoryUseCaseInterface,
    CreateInventoryMovementUseCaseInterface,
    CreateInventoryUseCaseInterface,
    DeleteInventoryCategoryUseCaseInterface,
    DeleteInventoryUseCaseInterface,
    GetInventoryCategoriesUseCaseInterface,
    GetInventoriesUseCaseInterface,
    GetInventoryMovementsUseCaseInterface,
    UpdateInventoryCategoryUseCaseInterface,
    UpdateInventoryUseCaseInterface,
};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    // ── Categorías ──────────────────────────────────────────────────────────

    public function getCategories(
        GetInventoryCategoriesUseCaseInterface $useCase
    ): JsonResponse {
        $result = $useCase->getAll();
        $code = $result['status'] === 0 ? JsonResponse::HTTP_OK : JsonResponse::HTTP_UNPROCESSABLE_ENTITY;
        return standardApiReponse($result['message'], $result['data'], $result['status'], $code);
    }

    public function createCategory(
        CreateInventoryCategoryRequest $request,
        CreateInventoryCategoryUseCaseInterface $useCase
    ): JsonResponse {
        $result = $useCase->create($request->validated());
        $code = $result['status'] === 0 ? JsonResponse::HTTP_CREATED : JsonResponse::HTTP_UNPROCESSABLE_ENTITY;
        return standardApiReponse($result['message'], $result['data'], $result['status'], $code);
    }

    public function updateCategory(
        int $id,
        UpdateInventoryCategoryRequest $request,
        UpdateInventoryCategoryUseCaseInterface $useCase
    ): JsonResponse {
        $data = $request->validated();
        $data['id'] = $id;
        $result = $useCase->create($data);
        $code = $result['status'] === 0 ? JsonResponse::HTTP_OK : JsonResponse::HTTP_UNPROCESSABLE_ENTITY;
        return standardApiReponse($result['message'], $result['data'], $result['status'], $code);
    }

    public function deleteCategory(
        int $id,
        DeleteInventoryCategoryUseCaseInterface $useCase
    ): JsonResponse {
        $result = $useCase->delete($id);
        $code = $result['status'] === 0 ? JsonResponse::HTTP_OK : JsonResponse::HTTP_NOT_FOUND;
        return standardApiReponse($result['message'], $result['data'], $result['status'], $code);
    }

    // ── Ítems ────────────────────────────────────────────────────────────────

    public function getAll(
        Request $request,
        GetInventoriesUseCaseInterface $useCase
    ): JsonResponse {
        $filters = [
            'search'          => $request->query('q'),
            'category_id'     => $request->query('category_id'),
            'location'        => $request->query('location'),
            'low_stock'       => $request->boolean('low_stock'),
            'sort_by'         => $request->query('sort_by', 'name'),
            'sort_direction'  => $request->query('sort_direction', 'asc'),
            'per_page'        => $request->query('per_page', 15),
        ];

        $result = $useCase->getAll($filters);
        $code = $result['status'] === 0 ? JsonResponse::HTTP_OK : JsonResponse::HTTP_UNPROCESSABLE_ENTITY;
        return standardApiReponse($result['message'], $result['data'], $result['status'], $code);
    }

    public function getById(
        int $id,
        GetInventoriesUseCaseInterface $useCase
    ): JsonResponse {
        $result = $useCase->getById($id);
        $code = $result['status'] === 0 ? JsonResponse::HTTP_OK : JsonResponse::HTTP_NOT_FOUND;
        return standardApiReponse($result['message'], $result['data'], $result['status'], $code);
    }

    public function create(
        CreateInventoryRequest $request,
        CreateInventoryUseCaseInterface $useCase
    ): JsonResponse {
        $result = $useCase->create($request->validated());
        $code = $result['status'] === 0 ? JsonResponse::HTTP_CREATED : JsonResponse::HTTP_UNPROCESSABLE_ENTITY;
        return standardApiReponse($result['message'], $result['data'], $result['status'], $code);
    }

    public function update(
        int $id,
        UpdateInventoryRequest $request,
        UpdateInventoryUseCaseInterface $useCase
    ): JsonResponse {
        $result = $useCase->update($id, $request->validated());
        $code = $result['status'] === 0 ? JsonResponse::HTTP_OK : JsonResponse::HTTP_NOT_FOUND;
        return standardApiReponse($result['message'], $result['data'], $result['status'], $code);
    }

    public function delete(
        int $id,
        DeleteInventoryUseCaseInterface $useCase
    ): JsonResponse {
        $result = $useCase->delete($id);
        $code = $result['status'] === 0 ? JsonResponse::HTTP_OK : JsonResponse::HTTP_NOT_FOUND;
        return standardApiReponse($result['message'], $result['data'], $result['status'], $code);
    }

    public function lowStock(
        GetInventoriesUseCaseInterface $useCase
    ): JsonResponse {
        $result = $useCase->getLowStock();
        $code = $result['status'] === 0 ? JsonResponse::HTTP_OK : JsonResponse::HTTP_UNPROCESSABLE_ENTITY;
        return standardApiReponse($result['message'], $result['data'], $result['status'], $code);
    }

    public function locations(
        GetInventoriesUseCaseInterface $useCase
    ): JsonResponse {
        $result = $useCase->getLocations();
        $code = $result['status'] === 0 ? JsonResponse::HTTP_OK : JsonResponse::HTTP_UNPROCESSABLE_ENTITY;
        return standardApiReponse($result['message'], $result['data'], $result['status'], $code);
    }

    // ── Movimientos ──────────────────────────────────────────────────────────

    public function createMovement(
        CreateInventoryMovementRequest $request,
        CreateInventoryMovementUseCaseInterface $useCase
    ): JsonResponse {
        $result = $useCase->create($request->validated());
        $code = $result['status'] === 0 ? JsonResponse::HTTP_CREATED : JsonResponse::HTTP_UNPROCESSABLE_ENTITY;
        return standardApiReponse($result['message'], $result['data'], $result['status'], $code);
    }

    public function getMovements(
        int $inventoryId,
        GetInventoryMovementsUseCaseInterface $useCase
    ): JsonResponse {
        $result = $useCase->getByItem($inventoryId);
        $code = $result['status'] === 0 ? JsonResponse::HTTP_OK : JsonResponse::HTTP_NOT_FOUND;
        return standardApiReponse($result['message'], $result['data'], $result['status'], $code);
    }

    public function getAllMovements(
        Request $request,
        GetInventoryMovementsUseCaseInterface $useCase
    ): JsonResponse {
        $filters = [
            'inventory_id' => $request->query('inventory_id'),
            'type'         => $request->query('type'),
            'reference'    => $request->query('reference'),
            'date_from'    => $request->query('date_from'),
            'date_to'      => $request->query('date_to'),
            'per_page'     => $request->query('per_page', 15),
        ];

        $result = $useCase->getAll($filters);
        $code = $result['status'] === 0 ? JsonResponse::HTTP_OK : JsonResponse::HTTP_UNPROCESSABLE_ENTITY;
        return standardApiReponse($result['message'], $result['data'], $result['status'], $code);
    }
}
