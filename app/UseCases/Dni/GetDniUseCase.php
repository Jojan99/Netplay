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
     
        $defaultConfig = [
            'host' => '190.144.128.35',
            'user' => 'admin',
            'pass' => 'net4dm1n1str4d0r',
            'port' => 8724,
            'timeout' => 60,
        ];
    
        $client = new \RouterOS\Client($defaultConfig);
    
       return $client;
    }
}
