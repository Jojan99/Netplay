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
 * La asignación real es user_data.ip_assignment_id → tabla_ips.id, pero acá
 * se buscaba por tabla_ips.id_user, y eso fallaba de dos maneras:
 *
 * - Si la ficha del cliente quedó a nombre de otro id_user, el update no
 *   encontraba nada: la IP cambiaba en el router y la plataforma seguía
 *   mostrando la vieja.
 * - Hay 102 fichas que varios clientes comparten. Actualizar la ficha les
 *   cambiaba la IP a todos los que la referencian.
 *
 * Ahora se actualiza la ficha sólo si es de este cliente y de nadie más; si
 * la comparte o no tiene, se le crea una propia.
 */
public function updateUserIp(int $userId, string $newIp): void
{
    $companyId = getSessionCompanyId();

    $asignacionId = \Illuminate\Support\Facades\DB::table('user_data')
        ->where('user_id', $userId)
        ->value('ip_assignment_id');

    $laComparten = $asignacionId
        ? \Illuminate\Support\Facades\DB::table('user_data')->where('ip_assignment_id', $asignacionId)->count()
        : 0;

    if ($asignacionId && $laComparten === 1) {
        TablaIp::where('id', $asignacionId)
            ->where('company_id', $companyId)
            ->update(['ip' => $newIp]);

        return;
    }

    $anterior = $asignacionId ? TablaIp::find($asignacionId) : null;

    $propia = TablaIp::create([
        'company_id' => $companyId,
        'id_user'    => $userId,
        'ip'         => $newIp,
        'name'       => $anterior->name ?? '',
        'mac'        => $anterior->mac ?? null,
        'active'     => 1,
    ]);

    \Illuminate\Support\Facades\DB::table('user_data')
        ->where('user_id', $userId)
        ->update(['ip_assignment_id' => $propia->id]);
}

}
