<?php

namespace App\Repositories\Interfaces;

use App\Http\Requests\Egresos\CreateEgresosRequest;

/**
 * Clase interfaz encargada de administrar el repositorio de usuarios
 *
 * @package App\Repositories\Interfaces
 * @author Netplay <sa.networkgolden@gmail.com>
 * @copyright 2023/06/9
 */
interface EgresosRepositoryInterface
{

    /**
     * @return mixed
     */
    public function createEgresos(CreateEgresosRequest $data): mixed;
 
    /**
     * @return mixed
     */
    public function getEgresosAll(): mixed;


     /**
     * @return mixed
     */
    public function getPriceEgresseAll(): mixed;
}
