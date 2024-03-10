<?php

namespace App\Repositories;

use App\Models\internet_plan;
use App\Repositories\Interfaces\InternetInfoRepositoryInterface;

use function Laravel\Prompts\error;

class InternetInfoRepository implements InternetInfoRepositoryInterface
{
    /**
     * @return mixed
     */
    public function getInternetPlanAll(): mixed
    {

        return internet_plan::select()->get();
    }
}
