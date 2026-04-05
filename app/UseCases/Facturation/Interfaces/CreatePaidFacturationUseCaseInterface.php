<?php

namespace App\UseCases\Facturation\Interfaces;

use App\Http\Requests\Facturation\CreateFacturationRequest;
use App\Http\Requests\Facturation\CreatePaidFacturationRequest;

/**
 * Clase interfaz del caso de uso para obtener la información de pqrs en el sistema
 *
 * @package App\UseCases\Menus\Interfaces
 * @author Network Golden <sa.networkgolden@gmail.com>
 * @copyright 2022/06/13
 */
interface CreatePaidFacturationUseCaseInterface
          
{
    /**
     * @param CreatePaidFacturationRequest $data
     * @return mixed
     */
    public function createpaidFacturation(CreatePaidFacturationRequest $data): mixed;


}
