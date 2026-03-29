<?php

namespace App\UseCases\Egresos\Interfaces;

use App\Http\Requests\Egresos\CreateEgresosRequest;
use App\Http\Requests\User\CreateUserDataRequest;

/**
 * Clase interfaz del caso de uso para obtener la información de pqrs en el sistema
 *
 * @package App\UseCases\Menus\Interfaces
 * @author Network Golden <sa.networkgolden@gmail.com>
 * @copyright 2022/06/13
 */
interface CreateEgresosUseCaseInterface
{
    /**
     * @param CreateUserDataRequest $data
     * @return mixed
     */
    public function createEgresos(CreateEgresosRequest $data): mixed;
}
