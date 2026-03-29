<?php

namespace App\Repositories\Interfaces;

use App\Http\Requests\Ticket\CreateTicketRequest;
use App\Http\Requests\Ticket\TicketRequest;

/**
 * Clase interfaz encargada de administrar el repositorio de usuarios
 *
 * @package App\Repositories\Interfaces
 * @author Netplay <sa.networkgolden@gmail.com>
 * @copyright 2023/06/9
 */
interface TicketRepositoryInterface
{
    /**
     * @return mixed
     */
    public function getTypePriorityAll(): mixed;

      /**
     * @return mixed
     */
    public function getTypeServiceAll(): mixed;

     /**
     * @return mixed
     */
    public function getTechnicaAll(): mixed;

    /**
     * @param CreateTicketRequest $data
     * @return mixed
     */
    public function createTicket(CreateTicketRequest $data): int;

    /**
     * @param TicketRequest $data
     * @return mixed
     */
    public function updateTicket(TicketRequest $data): mixed;

     /**
     * @return mixed
     */
    public function getTicketInProgressAll($status): mixed;
}
