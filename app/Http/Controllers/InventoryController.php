<?php

namespace App\Http\Controllers;

use App\Http\Requests\Inventory\CreateInventoryCategoryRequest;
use App\Http\Requests\Inventory\CreateInventoryMovementRequest;
use App\Http\Requests\Inventory\CreateInventoryRequest;
use App\Http\Requests\Inventory\UpdateInventoryRequest;
use App\UseCases\Inventory\Interfaces\CreateInventoryCategoryUseCaseInterface;
use App\UseCases\Inventory\Interfaces\CreateInventoryMovementUseCaseInterface;
use App\UseCases\Inventory\Interfaces\CreateInventoryUseCaseInterface;
use App\UseCases\Inventory\Interfaces\DeleteInventoryUseCaseInterface;
use App\UseCases\Inventory\Interfaces\GetInventoryCategoriesUseCaseInterface;
use App\UseCases\Inventory\Interfaces\GetInventoriesUseCaseInterface;
use App\UseCases\Inventory\Interfaces\GetInventoryMovementsUseCaseInterface;
use App\UseCases\Inventory\Interfaces\UpdateInventoryUseCaseInterface;
use Illuminate\Http\JsonResponse;

class InventoryController extends Controller
{
    // ── Categorías ──────────────────────────────────────────────────────────

    public function getCategories(
        GetInventoryCategoriesUseCaseInterface $useCase
    ): object {
        $result = $useCase->getAll();
        return standardApiReponse($result['message'], $result['data'], $result['status'], JsonResponse::HTTP_OK);
    }

    public function createCategory(
        CreateInventoryCategoryRequest $request,
        CreateInventoryCategoryUseCaseInterface $useCase
    ): object {
        $result = $useCase->create($request);
        return standardApiReponse($result['message'], $result['data'], $result['status'], JsonResponse::HTTP_OK);
    }

    // ── Ítems ────────────────────────────────────────────────────────────────

    public function getAll(
        GetInventoriesUseCaseInterface $useCase
    ): object {
        $result = $useCase->getAll();
        return standardApiReponse($result['message'], $result['data'], $result['status'], JsonResponse::HTTP_OK);
    }

    public function getById(
        int $id,
        GetInventoriesUseCaseInterface $useCase
    ): object {
        $result = $useCase->getById($id);
        return standardApiReponse($result['message'], $result['data'], $result['status'], JsonResponse::HTTP_OK);
    }

    public function create(
        CreateInventoryRequest $request,
        CreateInventoryUseCaseInterface $useCase
    ): object {
        $result = $useCase->create($request);
        return standardApiReponse($result['message'], $result['data'], $result['status'], JsonResponse::HTTP_OK);
    }

    public function update(
        int $id,
        UpdateInventoryRequest $request,
        UpdateInventoryUseCaseInterface $useCase
    ): object {
        $result = $useCase->update($id, $request);
        return standardApiReponse($result['message'], $result['data'], $result['status'], JsonResponse::HTTP_OK);
    }

    public function delete(
        int $id,
        DeleteInventoryUseCaseInterface $useCase
    ): object {
        $result = $useCase->delete($id);
        return standardApiReponse($result['message'], $result['data'], $result['status'], JsonResponse::HTTP_OK);
    }

    // ── Movimientos ──────────────────────────────────────────────────────────

    public function createMovement(
        CreateInventoryMovementRequest $request,
        CreateInventoryMovementUseCaseInterface $useCase
    ): object {
        $result = $useCase->create($request);
        return standardApiReponse($result['message'], $result['data'], $result['status'], JsonResponse::HTTP_OK);
    }

    public function getMovements(
        int $inventoryId,
        GetInventoryMovementsUseCaseInterface $useCase
    ): object {
        $result = $useCase->getByItem($inventoryId);
        return standardApiReponse($result['message'], $result['data'], $result['status'], JsonResponse::HTTP_OK);
    }

    public function getAllMovements(
        GetInventoryMovementsUseCaseInterface $useCase
    ): object {
        $result = $useCase->getAll();
        return standardApiReponse($result['message'], $result['data'], $result['status'], JsonResponse::HTTP_OK);
    }
}
