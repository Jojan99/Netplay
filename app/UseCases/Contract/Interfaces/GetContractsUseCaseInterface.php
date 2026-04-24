<?php

namespace App\UseCases\Contract\Interfaces;

interface GetContractsUseCaseInterface
{
    public function getAll(): mixed;
    public function getById(int $id): mixed;
}
