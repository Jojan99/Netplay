<?php

namespace App\UseCases\InfoInternet;

use App\Repositories\Interfaces\InternetInfoRepositoryInterface;
use Illuminate\Database\QueryException;
use App\Constants\ApiResponseConstants;
use App\Http\Requests\Internet\InternetIpRequest;
use App\UseCases\InfoInternet\Interfaces\GetIpAllByIdZoneUseCaseInterface;

/**
 * Clase del caso de uso GetCountrysUseCase
 *
 * @package App\UseCases\Pqr
 * @author Network Golden <sa.networkgolden@gmail.com>
 * @copyright 2022/06/13
 */
class GetIpAllByIdZoneUseCase implements GetIpAllByIdZoneUseCaseInterface
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
     * @param InternetIpRequest $data
     * @return mixed
     */
    public function getIpAllByIdZone(InternetIpRequest $data): mixed
    {
        try {
            if(getSessionUserProfileId() == 2){
                $getInternetPlanAll = $this->internetInfoRepositoryInterface->getIpAllByIdZone($data);
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
