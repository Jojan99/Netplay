<?php

namespace App\UseCases\Facturation;

use App\Constants\ApiResponseConstants;
use App\Http\Requests\Facturation\CreateFacturationRequest;
use App\Http\Requests\Facturation\GetDateFacturePendingnRequest;
use App\Repositories\Interfaces\FacturationRepositoryInterface;
use App\UseCases\Facturation\Interfaces\GetDateFacturePendingUseCaseInterface;
use App\UseCases\Facturation\Interfaces\GetDatePayFactureUseCaseInterface;
use Illuminate\Database\QueryException;

/**
 * Clase del caso de uso signin
 *
 * @package App\UseCases\User
 * @author NetPlay <Netplay>
 * @copyright 2023/09/22
 */
class GetDatePayFactureUseCase implements GetDatePayFactureUseCaseInterface
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
     */
    public function getDatePayFacture(GetDateFacturePendingnRequest $data): mixed
    {
        try {
            if (true) {
                $getDateFacturea = $this->facturationRepository->getDatePayFacture($data);
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
