<?php

namespace App\UseCases\User;

use App\Constants\ApiResponseConstants;
use App\Repositories\Interfaces\UserRepositoryInterface;
use App\UseCases\User\Interfaces\GetTotalPriceMonthUseCaseInterface;
use Illuminate\Database\QueryException;

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
    public function getTotalPriceMonth(int $year): mixed
    {
        try {
            $data = $this->userRepository->getTotalPriceMonth($year);
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
