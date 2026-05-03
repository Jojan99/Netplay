<?php

namespace App\UseCases\Contract;

use App\Constants\ApiResponseConstants;
use App\Repositories\Interfaces\ContractRepositoryInterface;
use App\UseCases\Contract\Interfaces\GetContractsUseCaseInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;

class GetContractsUseCase implements GetContractsUseCaseInterface
{
    public function __construct(
        private ContractRepositoryInterface $contractRepository
    ) {}

    public function getAll(): mixed
    {
        try {
            if (!sessionUserHasProfile('ADMIN', 'CONTADOR')) {
                return ['message' => 'Acción no permitida', 'status' => 1, 'data' => ApiResponseConstants::DATA_NULL];
            }
            $data = $this->contractRepository->getAll();
        } catch (QueryException $e) {
            return ['message' => 'Error al consultar contratos', 'status' => 1, 'data' => ApiResponseConstants::DATA_NULL];
        }
        return ['message' => 'Consulta realizada con éxito', 'status' => 0, 'data' => $data];
    }

    public function getById(int $id): mixed
    {
        try {
            if (!sessionUserHasProfile('ADMIN', 'CONTADOR')) {
                return ['message' => 'Acción no permitida', 'status' => 1, 'data' => ApiResponseConstants::DATA_NULL];
            }
            $data = $this->contractRepository->getById($id);
        } catch (ModelNotFoundException) {
            return ['message' => 'Contrato no encontrado', 'status' => 1, 'data' => ApiResponseConstants::DATA_NULL];
        } catch (QueryException $e) {
            return ['message' => 'Error al consultar contrato', 'status' => 1, 'data' => ApiResponseConstants::DATA_NULL];
        }
        return ['message' => 'Consulta realizada con éxito', 'status' => 0, 'data' => $data];
    }
}
