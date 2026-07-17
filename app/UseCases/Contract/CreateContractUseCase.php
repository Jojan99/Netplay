<?php

namespace App\UseCases\Contract;

use App\Constants\ApiResponseConstants;
use App\Repositories\Interfaces\ContractRepositoryInterface;
use App\UseCases\Contract\Interfaces\CreateContractUseCaseInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

class CreateContractUseCase implements CreateContractUseCaseInterface
{
    public function __construct(
        private ContractRepositoryInterface $contractRepository
    ) {}

    public function create(Request $request): mixed
    {
        try {
            if (!sessionUserHasProfile('ADMIN', 'CONTADOR')) {
                return ['message' => 'Acción no permitida', 'status' => 1, 'data' => ApiResponseConstants::DATA_NULL];
            }
            $data = $this->contractRepository->create($request->all());
        } catch (QueryException $e) {
            return ['message' => 'Error al crear contrato: ' . $e->getMessage(), 'status' => 1, 'data' => ApiResponseConstants::DATA_NULL];
        }
        return ['message' => 'Contrato creado exitosamente', 'status' => 0, 'data' => $data];
    }

    public function update(int $id, Request $request): mixed
    {
        try {
            if (!sessionUserHasProfile('ADMIN', 'CONTADOR')) {
                return ['message' => 'Acción no permitida', 'status' => 1, 'data' => ApiResponseConstants::DATA_NULL];
            }
            $data = $this->contractRepository->update($id, $request->only([
            'title', 'content', 'logo', 'active', 'installation_value', 'pdf_path',
        ]));
        } catch (ModelNotFoundException) {
            return ['message' => 'Contrato no encontrado', 'status' => 1, 'data' => ApiResponseConstants::DATA_NULL];
        } catch (QueryException $e) {
            return ['message' => 'Error al actualizar contrato', 'status' => 1, 'data' => ApiResponseConstants::DATA_NULL];
        }
        return ['message' => 'Contrato actualizado exitosamente', 'status' => 0, 'data' => $data];
    }

    public function delete(int $id): mixed
    {
        try {
            if (!sessionUserHasProfile('ADMIN')) {
                return ['message' => 'Acción no permitida', 'status' => 1, 'data' => ApiResponseConstants::DATA_NULL];
            }
            $this->contractRepository->delete($id);
        } catch (ModelNotFoundException) {
            return ['message' => 'Contrato no encontrado', 'status' => 1, 'data' => ApiResponseConstants::DATA_NULL];
        } catch (QueryException $e) {
            return ['message' => 'Error al eliminar contrato', 'status' => 1, 'data' => ApiResponseConstants::DATA_NULL];
        }
        return ['message' => 'Contrato eliminado exitosamente', 'status' => 0, 'data' => ApiResponseConstants::DATA_NULL];
    }
}
