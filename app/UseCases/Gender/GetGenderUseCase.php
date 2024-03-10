<?php

namespace App\UseCases\Gender;

use App\Repositories\Interfaces\GenderRepositoryInterface;
use App\UseCases\Gender\Interfaces\GetGenderUseCaseInterface;
use Illuminate\Database\QueryException;
use App\Constants\ApiResponseConstants;

/**
 * Clase del caso de uso GetCountrysUseCase
 *
 * @package App\UseCases\Pqr
 * @author Network Golden <sa.networkgolden@gmail.com>
 * @copyright 2022/06/13
 */
class GetGenderUseCase implements GetGenderUseCaseInterface
{
    /**
     * Constructor de la clase
     *
     * @param GenderRepositoryInterface $userRepository
     */
    public function __construct(
        private GenderRepositoryInterface $genderRepository
    ) {
    }

    /**
     * @return mixed
     */
    public function getGenderAll(): mixed
    {
        try {
            if(true){
                $userAll = $this->genderRepository->getGenderAll();
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
