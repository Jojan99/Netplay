<?php

namespace App\UseCases\Location;

use App\Repositories\Interfaces\LocationRepositoryInterface;
use App\UseCases\Location\Interfaces\LocationUseCaseInterface;
use Illuminate\Database\QueryException;
use App\Constants\ApiResponseConstants;

/**
 * Clase del caso de uso GetCountrysUseCase
 *
 * @package App\UseCases\Pqr
 * @author Network Golden <sa.networkgolden@gmail.com>
 * @copyright 2022/06/13
 */
class LocationUseCase implements LocationUseCaseInterface
{
    /**
     * Constructor de la clase
     *
     * @param LocationRepositoryInterface $locationRepositoryInterface
     */
    public function __construct(
        private LocationRepositoryInterface $locationRepositoryInterface
    ) {
    }

    /**
     * @return mixed
     */
    public function getneighborhoodAll(): mixed
    {
        try {
            if(true){
                $neighborhoodAll = $this->locationRepositoryInterface->getneighborhoodAll();
            }else{
                return [
                    'message' => 'Accion no permitida',
                    'status' => 1,
                    'data' => ApiResponseConstants::DATA_NULL
                ];
            }
        } catch (QueryException $err) {
            return [
                'message' => 'Ocurrio un error al consultar los generos barrios',
                'status' => 1,
                'data' => ApiResponseConstants::DATA_NULL
            ];
        }

        return [
            'message' => 'consulta realizada con exito',
            'status' => 0,
            'data' => $neighborhoodAll 
        ];
    }
}
