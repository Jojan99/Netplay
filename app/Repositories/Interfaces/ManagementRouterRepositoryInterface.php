<?php

namespace App\Repositories\Interfaces;

use App\Http\Requests\Gestions\GestionUserRequest;

/**
 * Clase interfaz encargada de administrar el repositorio de usuarios
 *
 * @package App\Repositories\Interfaces
 * @author Netplay <sa.networkgolden@gmail.com>
 * @copyright 2023/06/9
 */
interface ManagementRouterRepositoryInterface
{

    /**
     * @return mixed
     */
    public function UpdateStatus(GestionUserRequest|array $data): array;

    public function GetUsersPendding(): array;
}