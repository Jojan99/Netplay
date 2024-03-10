<?php

namespace App\Repositories;

use App\Models\Countrie;
use App\Repositories\Interfaces\LocationRepositoryInterface;

use function Laravel\Prompts\error;

class LocationRepository implements LocationRepositoryInterface
{
    /**
     * @return mixed
     */
    public function getneighborhoodAll(): mixed
    {

        return Countrie::where('active', 1)->get();
    }
}
