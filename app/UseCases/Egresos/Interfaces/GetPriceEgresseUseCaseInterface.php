<?php

namespace App\UseCases\Egresos\Interfaces;


/**
 * Clase interfaz del caso de uso para obtener la información de pqrs en el sistema
 *
 * @package App\UseCases\Menus\Interfaces
 * @author Network Golden <sa.networkgolden@gmail.com>
 * @copyright 2022/06/13
 */
interface GetPriceEgresseUseCaseInterface
{
    /**
     * @return mixed
     */
    public function getPriceEgresseAll(): mixed;
}
