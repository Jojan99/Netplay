<?php

namespace App\UseCases\Ticket;

use App\Repositories\Interfaces\GenderRepositoryInterface;
use Illuminate\Database\QueryException;
use App\Constants\ApiResponseConstants;
use App\Repositories\Interfaces\TicketRepositoryInterface;
use App\UseCases\Ticket\Interfaces\GetTypeServiceUseCaseInterface;

/**
 * Clase del caso de uso GetCountrysUseCase
 *
 * @package App\UseCases\Pqr
 * @author Network Golden <sa.networkgolden@gmail.com>
 * @copyright 2022/06/13
 */
class GetTypeServiceUseCase implements GetTypeServiceUseCaseInterface
{
    /**
     * Constructor de la clase
     *
     * @param TicketRepositoryInterface $ticketRepositoryInterface
     */
    public function __construct(
        private TicketRepositoryInterface $ticketRepositoryInterface
    ) {
    }

    /**
     * @return mixed
     */
    public function getTypeServiceAll(): mixed
    {
        try {
            if(sessionUserHasProfile('CONTADOR', 'ADMIN')){
                $userAll = $this->ticketRepositoryInterface->getTypeServiceAll();
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
