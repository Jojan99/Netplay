<?php

namespace App\UseCases\ManagementRouter\Interfaces;

use App\Http\Requests\Gestions\GestionUserRequest;

/**
 * Clase interfaz del caso de uso para obtener la información de pqrs en el sistema
 *
 * @package App\UseCases\Menus\Interfaces
 * @author Network Golden <sa.networkgolden@gmail.com>
 * @copyright 2022/06/13
 */
interface GetIpAvaliblesUseCaseInterface
{
    /**
     * @return mixed
     */
    public function GetIpAvalibles(GestionUserRequest $gestionUserRequest): mixed;


    public function autorizarServicio(GestionUserRequest $gestionUserRequest): array;

    /**
     * @return mixed
     */
    public function GetLanSegments(): mixed;

    public function registerIpInArp(
    string $ip,
    string $mac,
    string $vlan,
    string $comment
): bool;

    public function migrarIp(GestionUserRequest $request): array;

}
