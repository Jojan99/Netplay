<?php

namespace App\UseCases\InfoInternet;

use App\Repositories\Interfaces\InternetInfoRepositoryInterface;
use App\UseCases\InfoInternet\Interfaces\GetInternetPlanUseCaseInterface;
use Illuminate\Database\QueryException;
use App\Constants\ApiResponseConstants;

/**
 * Clase del caso de uso GetCountrysUseCase
 *
 * @package App\UseCases\Pqr
 * @author Network Golden <sa.networkgolden@gmail.com>
 * @copyright 2022/06/13
 */
class GetInternetPlanUseCase implements GetInternetPlanUseCaseInterface
{
    /**
     * Constructor de la clase
     *
     * @param InternetInfoRepositoryInterface $internetInfoRepositoryInterface
     */
    public function __construct(
        private InternetInfoRepositoryInterface $internetInfoRepositoryInterface
    ) {
    }

    /**
     * @return mixed
     */
    public function getInternetPlanAll(): mixed
    {
        try {
            if(getSessionUserProfileId() == 2){
                $getInternetPlanAll = $this->internetInfoRepositoryInterface->getInternetPlanAll();
            }else{
                return [
                    'message' => 'Accion no permitida',
                    'status' => 1,
                    'data' => ApiResponseConstants::DATA_NULL
                ];
            }
        } catch (QueryException $err) {
            return [
                'message' => 'Ocurrio un error al consultar los Internet Plan disponibles',
                'status' => 1,
                'data' => ApiResponseConstants::DATA_NULL
            ];
        }

        return [
            'message' => 'consulta realizada con exito',
            'status' => 0,
            'data' => $getInternetPlanAll 
        ];
    }
}
