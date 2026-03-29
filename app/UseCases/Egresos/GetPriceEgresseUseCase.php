<?php

namespace App\UseCases\Egresos;

use App\Repositories\Interfaces\GenderRepositoryInterface;
use Illuminate\Database\QueryException;
use App\Constants\ApiResponseConstants;
use App\Repositories\Interfaces\EgresosRepositoryInterface;
use App\UseCases\Egresos\Interfaces\GetPriceEgresseUseCaseInterface;

/**
 * Clase del caso de uso GetCountrysUseCase
 *
 * @package App\UseCases\Pqr
 * @author Network Golden <sa.networkgolden@gmail.com>
 * @copyright 2022/06/13
 */
class GetPriceEgresseUseCase implements GetPriceEgresseUseCaseInterface
{
    /**
     * Constructor de la clase
     *
     * @param GenderRepositoryInterface $userRepository
     */
    public function __construct(
        private EgresosRepositoryInterface $egresosRepository
    ) {
    }

    /**
     * @return mixed
     */
    public function getPriceEgresseAll(): mixed
    {
        try {

            error_log(getSessionUserProfileId());

            if(getSessionUserProfileId() == 2){
                $userAll = $this->egresosRepository->getPriceEgresseAll();
            }else{
                return [
                    'message' => 'Accion no permitida',
                    'status' => 1,
                    'data' => getSessionUserProfileId()
                ];
            }
        } catch (QueryException $err) {
            return [
                'message' => 'Ocurrio un error al consultar los egresos',
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
