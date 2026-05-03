<?php

namespace App\UseCases\Contract\Interfaces;

use Illuminate\Http\Request;

interface CreateContractUseCaseInterface
{
    public function create(Request $request): mixed;
    public function update(int $id, Request $request): mixed;
    public function delete(int $id): mixed;
}
