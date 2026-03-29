<?php

namespace App\Repositories;

use App\Http\Requests\Internet\InternetIpRequest;
use App\Models\InternetPlan;
use App\Models\DataCorte;
use App\Models\TablaIp;
use App\Repositories\Interfaces\InternetInfoRepositoryInterface;

use function Laravel\Prompts\error;

class InternetInfoRepository implements InternetInfoRepositoryInterface
{
    /**
     * @return mixed
     */
    public function getInternetPlanAll(): mixed
    {

        return InternetPlan::select()->get();
    }


    /**
     * @return mixed
     */
    public function getDataCorteAll(): mixed
    {

        return DataCorte::select()->get();
    }

    /**
     * @return mixed
     */
    public function getIpAllByIdZone(InternetIpRequest $data): mixed
    {
        return TablaIp::select()
        ->where('id_zona',$data['id'])
        ->where('active',0)
        ->get();
    }

    
/**
 * @return int
 */
public function AssignemetIpUser($id, $id_user): int
{
    $ip = TablaIp::create([
        'id_user' => $id_user,
        'active'  => 1,
        'ip'      => $id,
    ]);

    return $ip->id;
}

}
