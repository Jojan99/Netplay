<?php

namespace App\UseCases\Inventory\Interfaces;

use Illuminate\Http\Request;

interface CreateInventoryMovementUseCaseInterface
{
    public function create(Request $request): mixed;
}
