<?php

namespace App\UseCases\Inventory\Interfaces;

use Illuminate\Http\Request;

interface CreateInventoryUseCaseInterface
{
    public function create(Request $request): mixed;
}
