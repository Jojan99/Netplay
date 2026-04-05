<?php

namespace App\UseCases\Egresos\Interfaces;

interface GetIngresosDetailedUseCaseInterface
{
    public function getAll(?string $from, ?string $to): mixed;
}
