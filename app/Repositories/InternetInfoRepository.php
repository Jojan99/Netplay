<?php

namespace App\Repositories;

use App\Http\Requests\Internet\InternetIpRequest;
use App\Models\CompanyBillingSchedule;
use App\Models\InternetPlan;
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
        return InternetPlan::where('company_id', getSessionCompanyId())->get();
    }


    /**
     * Returns billing groups configured for the current company.
     * Maps to company_billing_schedules so each company only sees its own groups.
     * Returns objects with {id, data_cortes} to match legacy frontend contract.
     */
    public function getDataCorteAll(): mixed
    {
        $schedules = CompanyBillingSchedule::where('company_id', getSessionCompanyId())
            ->where('active', true)
            ->orderBy('grupo')
            ->get();

        return $schedules->map(function ($s) {
            $hour  = str_pad($s->billing_hour, 2, '0', STR_PAD_LEFT) . ':00';
            $label = "Grupo {$s->grupo} – Día {$s->billing_day} a las {$hour}";
            return (object) [
                'id'          => $s->grupo,
                'data_cortes' => $label,
            ];
        });
    }

    /**
     * @return mixed
     */
    public function getIpAllByIdZone(InternetIpRequest $data): mixed
    {
        return TablaIp::where('id_zona', $data['id'])
            ->where('company_id', getSessionCompanyId())
            ->where('active', 0)
            ->get();
    }


/**
 * @return int
 */
public function AssignemetIpUser($id, $id_user, string $mac = ''): int
{
    $ip = TablaIp::create([
        'company_id' => getSessionCompanyId(),
        'id_user'    => $id_user,
        'active'     => 1,
        'ip'         => $id,
        'mac'        => $mac ?: null,
    ]);

    return $ip->id;
}

public function updateIpMac(string $ip, string $mac): void
{
    TablaIp::where('ip', $ip)
        ->where('company_id', getSessionCompanyId())
        ->update(['mac' => $mac]);
}

}
