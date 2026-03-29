<?php

namespace App\UseCases\User;

use App\Constants\ApiResponseConstants;
use App\Http\Requests\User\CreateUserDataRequest;
use App\Repositories\Interfaces\UserRepositoryInterface;
use App\UseCases\User\Interfaces\UpdateUserDataUseCaseInterface;
use Illuminate\Database\QueryException;




/**
 * Clase del caso de uso signin
 *
 * @package App\UseCases\User
 * @author NetPlay <Netplay>
 * @copyright 2023/09/22
 */
class UpdateUserDataUseCase implements UpdateUserDataUseCaseInterface
{
    /**
     * Constructor de la clase
     *
     * @param UserRepositoryInterface $userRepository

     */

    public function __construct(
        private UserRepositoryInterface $userRepository,

    ) {
    }

    /**
     * @param CreateUserDataRequest $data
     * @return mixed
     */
    public function UpdateUserData(CreateUserDataRequest $data): mixed
    {
        if(getSessionUserProfileId() == 2){
            try {
                $this->userRepository->UpdateUserData($data);
            } catch (QueryException $err) {
                return [
                    'message' => 'Ha ocurrido un error al actualizar los datos',
                    'status' => 1,
                    'data' => ApiResponseConstants::DATA_NULL
                ];
            }
            return ['message' => 'Usuario Actualizado con éxito', 'status' => 0, 'data' => ApiResponseConstants::DATA_NULL];
        }else{
            return ['message' => 'Accion no permitida', 'status' => 1, 'data' => ApiResponseConstants::DATA_NULL];
        }
    }
}
