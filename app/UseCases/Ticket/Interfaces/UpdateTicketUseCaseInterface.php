<?php

namespace App\UseCases\Ticket\Interfaces;

use App\Http\Requests\Ticket\TicketRequest;

/**
 * Clase interfaz del caso de uso para obtener la información de pqrs en el sistema
 *
 * @package App\UseCases\Menus\Interfaces
 * @author Network Golden <sa.networkgolden@gmail.com>
 * @copyright 2022/06/13
 */
interface UpdateTicketUseCaseInterface
          
{
    /**
     * @param TicketRequest $data
     * @return mixed
     */
    public function updateTicket(TicketRequest $data): mixed;
}
