<?php

namespace App\Repositories\Interfaces;

use App\Http\Requests\Facturation\CreateFacturationRequest;

/**
 * Clase interfaz encargada de administrar el repositorio de usuarios
 *
 * @package App\Repositories\Interfaces
 * @author Network Golden <sa.networkgolden@gmail.com>
 * @copyright 2022/06/9
 */
interface FacturationRepositoryInterface{
/**
     * Método encargado de obtener los datos de un usuario por medio del nombre
     * de usuario
     *
     /**
     * @param string $id_user
     * @return mixed
     */
    public function getCabUserFacturation(string $id_user): mixed;

      /**
     * @param CreateFacturationRequest $data
     * @return mixed
     */
    public function createDetFacturation(CreateFacturationRequest $data): mixed;
    /**
     * @param string $id_user
     * @return mixed
     */
    public function createCabFacturation(string $id_user): mixed;

    /**
     * Método encargado de obtener los datos de un usuario por medio del nombre
     * de usuario
     *
     /**
     * @param string $id_user
     * @return mixed
     */
    public function getDateFacturePending(): mixed;
}