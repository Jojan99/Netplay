<?php

namespace App\Repositories\Interfaces;

use App\Http\Requests\User\CreateUserDataRequest;

/**
 * Clase interfaz encargada de administrar el repositorio de usuarios
 *
 * @package App\Repositories\Interfaces
 * @author Network Golden <sa.networkgolden@gmail.com>
 * @copyright 2022/06/9
 */
interface UserRepositoryInterface{



    /**
     * Método encargado de obtener los datos de un usuario por medio del nombre
     * de usuario
     *
     * @param string $userName
     * @return mixed
     */
    public function getUserLoggedIn(string $userName): mixed;
    
      /**
     * @param CreateUserDataRequest $data
     * @return mixed
     */
    public function createUser(CreateUserDataRequest $data): mixed;

    /**
     * @param CreateUserDataRequest $data
     * @return mixed
     */
    public function createUserData(CreateUserDataRequest $data): mixed;

     /**
     * @return mixed
     */
    public function getUserAll(): mixed;

    /**
     * @param string $email
     * @return mixed
     */
    public function validateUserEmail(string $email): mixed;

    /**
     * @param string $dni
     * @return mixed
     */
    public function validateUserDni(string $dni): mixed;

    /**
     * @param string $phone
     * @return mixed
     */
    public function validateUserPhone(string $phone): mixed;

     /**
     * @param CreateUserDataRequest $data
     * @return mixed
     */
    public function UpdateUserData(CreateUserDataRequest $data): mixed;

     /**
     * @param int $id
     * @return mixed
     */
    public function getUserById($id): mixed;
}