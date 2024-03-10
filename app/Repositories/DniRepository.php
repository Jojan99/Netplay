<?php

namespace App\Repositories;

use App\Models\Dni;
use App\Repositories\Interfaces\DniRepositoryInterface;


class DniRepository implements DniRepositoryInterface
{
    /**
     * @return mixed
     */
    public function getDniAll(): mixed
    {
        return Dni::where('active', 1)->get();
    }
}
