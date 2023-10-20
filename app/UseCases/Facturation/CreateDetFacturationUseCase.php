<?php

namespace App\UseCases\Facturation;

use App\Constants\ApiResponseConstants;
use App\Http\Requests\Facturation\CreateFacturationRequest;
use App\Repositories\Interfaces\FacturationRepositoryInterface;
use App\UseCases\Facturation\Interfaces\CreateDetFacturationUseCaseInterface;
use Illuminate\Database\QueryException;




/**
 * Clase del caso de uso signin
 *
 * @package App\UseCases\User
 * @author NetPlay <Netplay>
 * @copyright 2023/09/22
 */
class CreateDetFacturationUseCase implements CreateDetFacturationUseCaseInterface
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
     * @param CreateFacturationRequest $data
     * @return mixed
     */
    public function createDetFacturation(CreateFacturationRequest $data): mixed
    {
        try {
            if(getSessionUserProfileId() == 1){
                $CabUser = $this->facturationRepository->getCabUserFacturation($data['id_user']);
                if ($CabUser) {
                    $data['cab_id'] = $CabUser['id'];
                    $this->facturationRepository->createDetFacturation($data);
                } else {
                    return ['message' => 'Error creating user', 'data' => 9, 'status' => 1];
                }
            }else{
                return ['message' => 'No tienes esta accion permitida', 'data' => 9, 'status' => 1];
            }
           
        } catch (QueryException $err) {
            return ['message' => 'An error occurred while creating the user: ' . $err->getMessage(), 'data' => ApiResponseConstants::DATA_NULL, 'status' => 1];
        }
        return ['message' => 'Usuario creado con éxito', 'status' => 0, 'data' => ApiResponseConstants::DATA_NULL];
    }
}
