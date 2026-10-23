<?php

namespace App\Services\Contratos;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * La fecha de instalación de un cliente para su contrato: la de su orden de instalación terminada
 * si la tiene (es la real) y, si no, el día en que se le dio de alta en la plataforma.
 */
class FechaDeInstalacion
{
    public static function de(int $userId): ?Carbon
    {
        $terminada = DB::table('installation_orders as o')
            ->join('user_data as ud', 'ud.id', '=', 'o.user_data_id')
            ->where('ud.user_id', $userId)
            ->where('o.status', 'completed')
            ->whereNotNull('o.finished_at')
            ->orderBy('o.finished_at')
            ->value('o.finished_at');

        if ($terminada) {
            return Carbon::parse($terminada)->startOfDay();
        }

        $alta = DB::table('users')->where('id', $userId)->value('created_at');

        return $alta ? Carbon::parse($alta)->startOfDay() : null;
    }
}
