<?php

namespace App\Repositories;

use App\Models\Gender;
use App\Repositories\Interfaces\GenderRepositoryInterface;

use function Laravel\Prompts\error;

class GenderRepository implements GenderRepositoryInterface
{
    /**
     * @return mixed
     */
    public function getGenderAll(): mixed
    {

        return Gender::where('active', 1)->get();
    }
}
