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

     /**
     * @param int $id
     * @return mixed
     */
    public function DeleteUserData($id): mixed;

    public function getUserByIdBost($dni): mixed;

    /**
     * @param int $id
     * @return mixed
     */
    public function getCountUser(): mixed;

    /**
     * @param int $id
     * @return mixed
     */
    public function getTotalPriceMonth($year): mixed;

    /**
     * @param int $id
     * @return mixed
     */
    public function getTotalClientRegisterMonth($year): mixed;

    /**
     * @return mixed
     */
    public function getTrazaFacture(): mixed;

    /**
     * Crea un usuario de staff (admin, técnico, contador) para la empresa en sesión.
     */
    public function createStaff(mixed $data): mixed;

    /**
     * Verifica si el email ya existe para la empresa en sesión.
     */
    public function validateStaffEmail(string $email): mixed;

    /**
     * Verifica si el username ya existe globalmente.
     */
    public function validateStaffUsername(string $username): mixed;

    /**
     * Retorna todos los usuarios de staff de la empresa en sesión.
     */
    public function getStaff(): mixed;
}