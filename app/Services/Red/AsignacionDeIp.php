<?php

namespace App\Services\Red;

use App\Models\TablaIp;
use Illuminate\Support\Facades\DB;

/**
 * Le pone una IP a un cliente sin pisarle la de nadie más.
 *
 * La asignación vive en user_data.ip_assignment_id → tabla_ips.id, y hay
 * registros de tabla_ips que quedaron referenciados por varios clientes a la
 * vez. Escribir sobre uno de esos les cambia la IP a todos, así que cuando
 * pasa se le arma al cliente su propio registro.
 *
 * Recibe la empresa por parámetro a propósito: esto también corre desde el
 * comando de sincronización, donde no hay sesión de la que sacarla.
 */
class AsignacionDeIp
{
    /**
     * @return string 'sin_cambio' | 'actualizada' | 'ficha_propia'
     */
    public static function asignar(int $userId, string $nuevaIp, int $companyId): string
    {
        $asignacionId = DB::table('user_data')
            ->where('user_id', $userId)
            ->value('ip_assignment_id');

        $actual = $asignacionId
            ? TablaIp::where('id', $asignacionId)->value('ip')
            : null;

        if ($actual === $nuevaIp) {
            return 'sin_cambio';
        }

        $laComparten = $asignacionId
            ? DB::table('user_data')->where('ip_assignment_id', $asignacionId)->count()
            : 0;

        if ($asignacionId && $laComparten === 1) {
            TablaIp::where('id', $asignacionId)
                ->where('company_id', $companyId)
                ->update(['ip' => $nuevaIp]);

            return 'actualizada';
        }

        $anterior = $asignacionId ? TablaIp::find($asignacionId) : null;

        $propia = TablaIp::create([
            'company_id' => $companyId,
            'id_user'    => $userId,
            'ip'         => $nuevaIp,
            'name'       => $anterior->name ?? '',
            'mac'        => $anterior->mac ?? null,
            'active'     => 1,
        ]);

        DB::table('user_data')
            ->where('user_id', $userId)
            ->update(['ip_assignment_id' => $propia->id]);

        return 'ficha_propia';
    }
}
