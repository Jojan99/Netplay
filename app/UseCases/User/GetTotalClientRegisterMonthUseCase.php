<?php

namespace App\UseCases\User;

use App\Constants\ApiResponseConstants;
use App\Repositories\Interfaces\UserRepositoryInterface;
use App\UseCases\User\Interfaces\GetCountUserUseCaseInterface;
use App\UseCases\User\Interfaces\GetTotalClientRegisterMonthUseCaseInterface;
use Carbon\Carbon;
use Illuminate\Database\QueryException;




/**
 * Clase del caso de uso signin
 *
 * @package App\UseCases\User
 * @author NetPlay <Netplay>
 * @copyright 2023/09/22
 */
class GetTotalClientRegisterMonthUseCase implements GetTotalClientRegisterMonthUseCaseInterface
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
    public function GetTotalClientRegisterMonth(): mixed
    {
        $year = Carbon::now()->year;

        try {
            if(getSessionUserProfileId() == 2){
                $data = $this->userRepository->GetTotalClientRegisterMonth($year);


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
