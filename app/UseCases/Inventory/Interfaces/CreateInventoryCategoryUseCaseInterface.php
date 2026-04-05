<?php

namespace App\UseCases\Inventory\Interfaces;

use Illuminate\Http\Request;

interface CreateInventoryCategoryUseCaseInterface
{
    public function create(Request $request): mixed;
}
