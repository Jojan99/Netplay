<?php

namespace App\UseCases\Dni;

use App\Repositories\Interfaces\DniRepositoryInterface;
use App\UseCases\Dni\Interfaces\GetDniUseCaseInterface;
use Illuminate\Database\QueryException;
use App\Constants\ApiResponseConstants;

/**
 * Clase del caso de uso GetCountrysUseCase
 *
 * @package App\UseCases\Pqr
 * @author Network Golden <sa.networkgolden@gmail.com>
 * @copyright 2022/06/13
 */
class GetDniUseCase implements GetDniUseCaseInterface
{
    /**
     * Constructor de la clase
     *
     * @param DniRepositoryInterface $dniRepositoryInterface
     */
    public function __construct(
        private DniRepositoryInterface $dniRepositoryInterface
    ) {
    }

    /**
     * @return mixed
     */
    public function getDniAll(): mixed
    {
        try {
            if(true){
                $getDniAll = $this->dniRepositoryInterface->getDniAll();
            }else{
                return [
                    'message' => 'Accion no permitida',
                    'status' => 1,
                    'data' => ApiResponseConstants::DATA_NULL
                ];
            }
        } catch (QueryException $err) {
            return [
                'message' => 'Ocurrio un error al consultar los generos disponibles',
                'status' => 1,
                'data' => ApiResponseConstants::DATA_NULL
            ];
        }

        return [
            'message' => 'consulta realizada con exito',
            'status' => 0,
            'data' => $getDniAll 
        ];
    }


    public function conection(){
        // Deshabilitada: se conectaba al MikroTik de Netplay con credenciales fijas
        // en el código. Los routers de cada empresa van por ConectionRouterManager.
        throw new \RuntimeException('Función deshabilitada');
    }
}
