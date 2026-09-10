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

/**
 * Le cambia la IP a un cliente sin tocar la de nadie más.
 *
 * Antes se buscaba por tabla_ips.id_user cuando la asignación real es
 * user_data.ip_assignment_id, y eso fallaba de dos maneras: si el registro
 * había quedado a nombre de otro id_user el update no encontraba nada — la IP
 * cambiaba en el router y la plataforma seguía mostrando la vieja — y si el
 * registro era compartido les cambiaba la IP a todos los que lo referencian.
 *
 * La lógica vive en AsignacionDeIp porque el comando de sincronización la
 * necesita igual, y ahí no hay sesión de la que sacar la empresa.
 */
public function updateUserIp(int $userId, string $newIp): void
{
    \App\Services\Red\AsignacionDeIp::asignar($userId, $newIp, (int) getSessionCompanyId());
}


}
