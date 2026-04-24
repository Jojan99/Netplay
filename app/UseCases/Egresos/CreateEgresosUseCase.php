<?php

namespace App\UseCases\Egresos;

use App\Constants\ApiResponseConstants;
use App\Http\Requests\Egresos\CreateEgresosRequest;
use App\Repositories\Interfaces\UserRepositoryInterface;
use App\Repositories\Interfaces\FacturationRepositoryInterface;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use App\Repositories\Interfaces\InternetInfoRepositoryInterface;
use App\UseCases\Egresos\Interfaces\CreateEgresosUseCaseInterface;
use App\Repositories\Interfaces\EgresosRepositoryInterface;

/**
 * Clase del caso de uso signin
 *
 * @package App\UseCases\User
 * @author NetPlay <Netplay>
 * @copyright 2023/09/22
 */
class CreateEgresosUseCase implements CreateEgresosUseCaseInterface
{
    /**
     * Constructor de la clase
     *
     * @param UserRepositoryInterface $userRepository
     * @param FacturationRepositoryInterface $facturationRepositoryInterface
     * @param InternetInfoRepositoryInterface $internetInfoRepository

     */

    public function __construct(
    
        private EgresosRepositoryInterface $egresosRepository,
    ) {
    }

    /**
     * @param CreateEgresosRequest $data
     * @return mixed
     */
    public function createEgresos(CreateEgresosRequest $data): mixed
    {
        try {
            if(sessionUserHasProfile('CONTADOR', 'ADMIN')){
                
                $user = $this->egresosRepository->createEgresos($data);
            }

        } catch (QueryException $err) {
            return ['message' => 'An error occurred while creating the user: ' . $err->getMessage(), 'data' => ApiResponseConstants::DATA_NULL, 'status' => 1];
        }

        return ['message' => 'Egresos creado con éxito', 'status' => 0, 'data' => ApiResponseConstants::DATA_NULL];
    }
}
