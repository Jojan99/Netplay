<?php

namespace App\UseCases\Egresos\Interfaces;

interface GetPriceEgresseUseCaseInterface
{
    public function getPriceEgresseAll(): mixed;
    public function getPriceEgresseByRange(?string $from, ?string $to): mixed;
}
