<?php

namespace App\UseCases\Egresos;

use App\Repositories\Interfaces\GenderRepositoryInterface;
use Illuminate\Database\QueryException;
use App\Constants\ApiResponseConstants;
use App\UseCases\Egresos\Interfaces\GetEgresosUseCaseInterface;
use App\Repositories\Interfaces\EgresosRepositoryInterface;

/**
 * Clase del caso de uso GetCountrysUseCase
 *
 * @package App\UseCases\Pqr
 * @author Network Golden <sa.networkgolden@gmail.com>
 * @copyright 2022/06/13
 */
class GetEgresosUseCase implements GetEgresosUseCaseInterface
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
    public function getEgresosAll(): mixed
    {
        try {
            if(sessionUserHasProfile('CONTADOR', 'ADMIN')){
                $userAll = $this->egresosRepository->getEgresosAll();
            }else{
                return [
                    'message' => 'Accion no permitida',
                    'status' => 1,
                    'data' => getSessionUserProfileId()
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
            'data' => $userAll 
        ];
    }
}
