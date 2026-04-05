<?php

namespace App\UseCases\User;

use App\Constants\ApiResponseConstants;
use App\Repositories\Interfaces\UserRepositoryInterface;
use App\UseCases\User\Interfaces\GetUserByIdUseCaseInterface;
use Illuminate\Database\QueryException;

/**
 * Clase del caso de uso GetCountrysUseCase
 *
 * @package App\UseCases\Pqr
 * @author Network Golden <sa.networkgolden@gmail.com>
 * @copyright 2022/06/13
 */
class GetUserByIdUseCase implements GetUserByIdUseCaseInterface
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
     * @param int $id
     * @return mixed
     */
    public function getUserById($id): mixed
    {
        
        try {
            if(true){
                $getUserById = $this->userRepository->getUserById($id);
            }else{
                return [
                    'message' => 'Accion no permitida',
                    'status' => 1,
                    'data' => ApiResponseConstants::DATA_NULL
                ];
            }
        } catch (QueryException $err) {
            return [
                'message' => 'Ocurrio un error al consultar el usuario',
                'status' => 1,
                'data' => ApiResponseConstants::DATA_NULL
            ];
        }

     if (empty($getUserById)) {
    return [
        'message' => 'No se encontraron datos para el usuario',
        'status' => 1,
        'data' => null
            ];
            }

        return [
        'message' => 'Consulta realizada con éxito',
        'status' => 0,
        'data' => $getUserById
];
    }


    /**
     * @param int $id
     * @return mixed
     */
    public function getUserByIdBost($id): mixed
    {
        
        try {
            if(true){
                $getUserById = $this->userRepository->getUserByIdBost($id);
            }else{
                return [
                    'message' => 'Accion no permitida',
                    'status' => 1,
                    'data' => ApiResponseConstants::DATA_NULL
                ];
            }
        } catch (QueryException $err) {
            return [
                'message' => 'Ocurrio un error al consultar el usuario',
                'status' => 1,
                'data' => ApiResponseConstants::DATA_NULL
            ];
        }

     if (empty($getUserById)) {
    return [
        'message' => 'No se encontraron datos para el usuario',
        'status' => 1,
        'data' => null
            ];
            }

        return [
        'message' => 'Consulta realizada con éxito',
        'status' => 0,
        'data' => $getUserById
];
    }
}
