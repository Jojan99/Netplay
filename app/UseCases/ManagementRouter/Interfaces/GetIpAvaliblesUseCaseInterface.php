<?php

namespace App\UseCases\ManagementRouter\Interfaces;

use App\Http\Requests\Gestions\GestionUserRequest;

interface GetIpAvaliblesUseCaseInterface
{
    public function GetIpAvalibles(GestionUserRequest $gestionUserRequest, ?int $routerId = null): mixed;

    public function autorizarServicio(GestionUserRequest $gestionUserRequest, ?int $routerId = null): array;

    public function getLanSegments(?int $routerId = null): mixed;

    public function registerIpInArp(string $ip, string $mac, string $vlan, string $comment, ?int $routerId = null): bool;

    public function migrarIp(GestionUserRequest $request, ?int $routerId = null): array;
}
