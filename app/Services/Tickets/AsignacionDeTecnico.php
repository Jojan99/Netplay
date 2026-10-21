<?php

namespace App\Services\Tickets;

use Illuminate\Support\Facades\DB;

/**
 * El técnico al que le toca un ticket que nadie asignó: el de menos tickets abiertos.
 *
 * Los tickets que abre el asistente por WhatsApp (o el portal del cliente) se creaban
 * sin técnico, y el listado de tickets sólo mostraba los que tenían uno: quedaban
 * guardados pero nadie los veía ni los atendía (32 abiertos el 19-10).
 */
class AsignacionDeTecnico
{
    /** Estados que cuentan como carga: abierto y en curso. */
    private const ABIERTOS = [1, 2];

    /**
     * El técnico activo de la empresa con menos tickets abiertos (en empate, el de id menor).
     *
     * @param array<int,int> $sumar carga ya repartida en esta misma vuelta (técnico => tickets)
     * @return array{id:int, nombre:string}|null
     */
    public static function menosCargado(int $companyId, array $sumar = []): ?array
    {
        $tecnicos = DB::table('users as u')
            ->join('profiles as p', 'p.id', '=', 'u.profile_id')
            ->join('user_data as ud', 'ud.user_id', '=', 'u.id')
            ->where('u.company_id', $companyId)
            ->where('p.company_id', $companyId)
            ->where('p.name', 'TECNICO')
            ->where('u.active', 1)
            ->get(['u.id', 'ud.names', 'ud.lastname']);

        if ($tecnicos->isEmpty()) {
            return null;
        }

        $carga = DB::table('tickets')
            ->where('company_id', $companyId)
            ->whereIn('status_id', self::ABIERTOS)
            ->whereIn('technical_id', $tecnicos->pluck('id'))
            ->groupBy('technical_id')
            ->pluck(DB::raw('COUNT(*)'), 'technical_id');

        $elegido = $tecnicos
            ->sortBy(fn ($t) => [(int) ($carga[$t->id] ?? 0) + (int) ($sumar[$t->id] ?? 0), (int) $t->id])
            ->first();

        return ['id' => (int) $elegido->id, 'nombre' => trim("{$elegido->names} {$elegido->lastname}")];
    }
}
