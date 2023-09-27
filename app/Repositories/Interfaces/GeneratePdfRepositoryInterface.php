<?php

namespace App\Repositories\Interfaces;


/**
 * Clase interfaz encargada de administrar el repositorio de usuarios
 *
 * @package App\Repositories\Interfaces
 * @author Network Golden <sa.networkgolden@gmail.com>
 * @copyright 2022/06/9
 */
interface GeneratePdfRepositoryInterface{

    /**
     * @param int $sponsor_id
     * @return mixed
     */
    public function generatePdf(): mixed;
}