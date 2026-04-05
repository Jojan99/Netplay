<?php

namespace App\UseCases\User;

use App\Constants\ApiResponseConstants;
use App\Repositories\Interfaces\UserRepositoryInterface;
use App\UseCases\User\Interfaces\GetCountUserUseCaseInterface;
use App\UseCases\User\Interfaces\GetTotalPriceMonthUseCaseInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * Clase del caso de uso signin
 *
 * @package App\UseCases\User
 * @author NetPlay <Netplay>
 * @copyright 2023/09/22
 */
class GetTotalPriceMonthUseCase implements GetTotalPriceMonthUseCaseInterface
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
     * @param int $id
     * 
     */
    public function getTotalPriceMonth(): mixed
    {
        $year = Carbon::now()->year;
        try {


            if(getSessionUserProfileId() == 2){
                $data = $this->userRepository->getTotalPriceMonth($year);

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
