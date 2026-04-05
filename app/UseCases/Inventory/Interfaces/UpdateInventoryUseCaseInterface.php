<?php

namespace App\UseCases\Inventory\Interfaces;

use Illuminate\Http\Request;

interface UpdateInventoryUseCaseInterface
{
    public function update(int $id, Request $request): mixed;
}
