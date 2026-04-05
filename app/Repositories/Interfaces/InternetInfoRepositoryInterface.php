<?php

namespace App\Repositories\Interfaces;

use App\Http\Requests\Internet\InternetIpRequest;

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


     /**
     * @return mixed
     */
    public function getDataCorteAll(): mixed;

     /**
     * @return mixed
     */
    public function getIpAllByIdZone(InternetIpRequest $data): mixed;

/**
 * @return int
 */
public function AssignemetIpUser($id, $id_user, string $mac = ''): int;

public function updateIpMac(string $ip, string $mac): void;

}
