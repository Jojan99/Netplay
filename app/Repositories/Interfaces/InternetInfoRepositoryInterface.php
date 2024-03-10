<?php

namespace App\Repositories\Interfaces;


/**
 * Clase interfaz encargada de administrar el repositorio de usuarios
 *
 * @package App\Repositories\Interfaces
 * @author Netplay <sa.networkgolden@gmail.com>
 * @copyright 2023/06/9
 */
interface InternetInfoRepositoryInterface
{

    /**
     * @return mixed
     */
    public function getInternetPlanAll(): mixed;
}
