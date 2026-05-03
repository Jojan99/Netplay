<?php

namespace App\UseCases\User;

use App\Constants\ApiResponseConstants;
use App\Repositories\Interfaces\UserRepositoryInterface;
use App\UseCases\User\Interfaces\GetCountUserUseCaseInterface;
use App\UseCases\User\Interfaces\GetTotalClientRegisterMonthUseCaseInterface;
use App\UseCases\User\Interfaces\GetTrazaFactureUseCaseInterface;
use Carbon\Carbon;
use Illuminate\Database\QueryException;




/**
 * Clase del caso de uso signin
 *
 * @package App\UseCases\User
 * @author NetPlay <Netplay>
 * @copyright 2023/09/22
 */
class GetTrazaFactureUseCase implements GetTrazaFactureUseCaseInterface
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
     * @return mixed
     */
    public function getTrazaFacture(): mixed
    {
        try {
            if(sessionUserHasProfile('CONTADOR', 'ADMIN')){
                $data = $this->userRepository->getTrazaFacture();

            }else{

                return [
                    'message' => 'Accion no permitida',
                    'status' => 1,
                    'data' => ApiResponseConstants::DATA_NULL
                ];
            }
        } catch (QueryException $err) {
            return [
                'message' => 'Ha ocurrido un error en la consulta',
                'status' => 1,
                'data' => ApiResponseConstants::DATA_NULL
            ];
        }
        return ['message' => 'Consulta realizada con exito', 'status' => 0, 'data' => $data];
    }
}
