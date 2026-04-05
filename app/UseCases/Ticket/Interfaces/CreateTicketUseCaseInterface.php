<?php

namespace App\UseCases\Ticket\Interfaces;

use App\Http\Requests\Facturation\CreateFacturationRequest;
use App\Http\Requests\Facturation\CreatePaidFacturationRequest;
use App\Http\Requests\Ticket\CreateTicketRequest;

/**
 * Clase interfaz del caso de uso para obtener la información de pqrs en el sistema
 *
 * @package App\UseCases\Menus\Interfaces
 * @author Network Golden <sa.networkgolden@gmail.com>
 * @copyright 2022/06/13
 */
interface CreateTicketUseCaseInterface
          
{
    /**
     * @param CreateTicketRequest $data
     * @return mixed
     */
    public function createTicket(CreateTicketRequest $data): mixed;


}
