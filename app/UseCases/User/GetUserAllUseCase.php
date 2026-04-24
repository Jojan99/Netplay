<?php

namespace App\UseCases\User;

use App\Constants\ApiResponseConstants;
use App\Repositories\Interfaces\UserRepositoryInterface;
use App\UseCases\User\Interfaces\GetUserAllUseCaseInterface;
use Illuminate\Database\QueryException;

/**
 * Clase del caso de uso GetCountrysUseCase
 *
 * @package App\UseCases\Pqr
 * @author Network Golden <sa.networkgolden@gmail.com>
 * @copyright 2022/06/13
 */
class GetUserAllUseCase implements GetUserAllUseCaseInterface
{
    /**
     * Constructor de la clase
     *
     * @param UserRepositoryInterface $userRepository
     */
    public function __construct(
        private UserRepositoryInterface $userRepository
    ) {
    }

    /**
     * @return mixed
     */
    public function getUserAll(): mixed
    {
        try {

        
            if(sessionUserHasProfile('CONTADOR', 'ADMIN')){
                $userAll = $this->userRepository->getUserAll();
            }else{

                return [
                    'message' => 'Accion no permitida',
                    'status' => 1,
                    'data' => ApiResponseConstants::DATA_NULL
                ];
            }
        } catch (QueryException $err) {
            return [
                'message' => 'Ocurrio un error al consultar los generos disponibles',
                'status' => 1,
                'data' => ApiResponseConstants::DATA_NULL
            ];
        }

        return [
            'message' => 'consulta realizada con exito',
            'status' => 0,
            'data' => $userAll 
        ];
    }
}
