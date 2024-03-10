<?php

namespace App\UseCases\Facturation;

use App\Constants\ApiResponseConstants;
use App\Http\Requests\Facturation\CreateFacturationRequest;
use App\Repositories\Interfaces\FacturationRepositoryInterface;
use App\UseCases\Facturation\Interfaces\GetDataInfoPenddingFactureUseCaseInterface;
use Illuminate\Database\QueryException;




/**
 * Clase del caso de uso signin
 *
 * @package App\UseCases\User
 * @author NetPlay <Netplay>
 * @copyright 2023/09/22
 */
class GetDataInfoPenddingFactureUseCase implements GetDataInfoPenddingFactureUseCaseInterface
{
    /**
     * Constructor de la clase
     *
     * @param FacturationRepositoryInterface $facturationRepository

     */

    public function __construct(
        private FacturationRepositoryInterface $facturationRepository,

    ) {
    }

    /**
x     * @return mixed
     */
    public function getDataInfoPenddingFacture(): mixed
    {
        error_log("LLEGAAAAA");

        try {
            if (true) {
                $getDateFacturea =  $this->facturationRepository->getDataInfoPenddingFacture();
            } else {
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
            'data' => $getDateFacturea
        ];
    }
}
