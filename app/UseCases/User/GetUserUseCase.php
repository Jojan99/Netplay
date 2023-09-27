<?php

namespace App\UseCases\User;

use App\Http\Resources\GetUserData;
use App\Repositories\Interfaces\UserRepositoryInterface;
use App\UseCases\User\Interfaces\GetUserUseCaseInterface;

/**
 * Clase del caso de uso signin
 *
 * @package App\UseCases\Pqr
 * @author Network Golden <sa.networkgolden@gmail.com>
 * @copyright 2022/06/13
 */
class GetUserUseCase implements GetUserUseCaseInterface
{
    /**
     * Constructor de la clase
     *
     * @param UserRepositoryInterface $userRepository
     */
    public function __construct(
        private UserRepositoryInterface $userRepository
    ) {
    }

    /**
     * Método encargado de obtener los datos de un usuario por medio del nombre
     * de usuario
     * @param string $userName
     * @return mixed
     */
    public function getUserLoggedIn(string $userName): mixed
    {
        return $this->userRepository->getUserLoggedIn($userName);
    }
}
