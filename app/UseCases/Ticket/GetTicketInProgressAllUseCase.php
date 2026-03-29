<?php

namespace App\UseCases\Ticket;

use Illuminate\Database\QueryException;
use App\Constants\ApiResponseConstants;
use App\Repositories\Interfaces\TicketRepositoryInterface;
use App\UseCases\Ticket\Interfaces\GetTicketInProgressAllUseCaseInterface;

/**
 * Clase del caso de uso GetCountrysUseCase
 *
 * @package App\UseCases\Pqr
 * @author Network Golden <sa.networkgolden@gmail.com>
 * @copyright 2022/06/13
 */
class GetTicketInProgressAllUseCase implements GetTicketInProgressAllUseCaseInterface
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
    public function getTicketInProgressAll($status): mixed
    {
        try {
            if(getSessionUserProfileId() != 1){
                $userAll = $this->ticketRepositoryInterface->getTicketInProgressAll($status);
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
