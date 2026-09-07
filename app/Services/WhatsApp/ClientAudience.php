<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A quiénes les toca un envío.
 *
 * Traduce los filtros que se eligen en el panel a la consulta de clientes, y
 * es el único sitio donde se decide qué significa "activo", "suspendido" o
 * "con deuda". Si mañana cambia el criterio, cambia aquí y cambia en todas
 * partes: el envío masivo, los recordatorios y el conteo previo usan esto.
 */
class ClientAudience
{
    /** Estado del servicio, como lo guarda user_data.status_internet_id. */
    public const SERVICIO_ACTIVO     = 1;
    public const SERVICIO_SUSPENDIDO = 2;

    /** Filtros aceptados, con lo que significan para quien los elige. */
    public const FILTROS = [
        'solo_vigentes' => [
            'label'       => 'Solo clientes vigentes',
            'description' => 'Deja fuera a los retirados.',
            'default'     => true,
        ],
        'servicio' => [
            'label'       => 'Estado del servicio',
            'opciones'    => [
                'todos'      => 'Con servicio y suspendidos',
                'activo'     => 'Solo con servicio activo',
                'suspendido' => 'Solo suspendidos',
            ],
            'default'     => 'todos',
        ],
        'deuda' => [
            'label'    => 'Facturas pendientes',
            'opciones' => [
                'todos' => 'Deban o no',
                'con'   => 'Solo los que deben',
                'sin'   => 'Solo los que están al día',
            ],
            'default'  => 'todos',
        ],
    ];

    /**
     * Los clientes que reciben, ya sin los excluidos y sin los que no tienen
     * un teléfono al que escribirles.
     *
     * @param  array  $filtros           Ver FILTROS.
     * @param  array  $excluidos         user_id que el operador quitó a mano.
     * @return Collection<int, object>   user_id, dni, names, lastname, phone, plan…
     */
    public function resolve(int $companyId, array $filtros = [], array $excluidos = []): Collection
    {
        $servicio = $filtros['servicio'] ?? 'todos';
        $deuda    = $filtros['deuda'] ?? 'todos';

        $query = DB::table('user_data as ud')
            ->leftJoin('internet_plans as ip', 'ip.id', '=', 'ud.internet_plans_id')
            ->where('ud.company_id', $companyId)
            // Sin teléfono no hay a dónde mandar: se descartan antes de contar,
            // para que el número que se confirma en pantalla sea el real.
            ->whereRaw("CHAR_LENGTH(REGEXP_REPLACE(COALESCE(ud.phone,''), '[^0-9]', '')) >= 10");

        if ($filtros['solo_vigentes'] ?? true) {
            $query->where('ud.active', 1);
        }

        if ($servicio === 'activo') {
            $query->where('ud.status_internet_id', self::SERVICIO_ACTIVO);
        } elseif ($servicio === 'suspendido') {
            $query->where('ud.status_internet_id', self::SERVICIO_SUSPENDIDO);
        }

        if ($deuda !== 'todos') {
            $conDeuda = $this->userIdsConDeuda($companyId);

            if ($deuda === 'con') {
                if ($conDeuda === []) {
                    return collect();
                }
                $query->whereIn('ud.user_id', $conDeuda);
            } else {
                $query->whereNotIn('ud.user_id', $conDeuda ?: [0]);
            }
        }

        if ($excluidos !== []) {
            $query->whereNotIn('ud.user_id', $excluidos);
        }

        return $query
            ->orderBy('ud.names')
            ->get([
                'ud.user_id',
                'ud.dni',
                'ud.names',
                'ud.lastname',
                'ud.phone',
                'ud.status_internet_id',
                'ud.active',
                DB::raw('ip.plan_name as plan'),
            ]);
    }

    /** Solo cuántos son. Para confirmar antes de gastar mensajes. */
    public function count(int $companyId, array $filtros = [], array $excluidos = []): int
    {
        return $this->resolve($companyId, $filtros, $excluidos)->count();
    }

    /**
     * Clientes con al menos una factura sin saldar.
     *
     * Se calcula en SQL y no factura por factura: son novecientos clientes y
     * recorrerlos en PHP haría que el conteo de la pantalla tardara segundos.
     *
     * @return array<int, int>
     */
    public function userIdsConDeuda(int $companyId): array
    {
        return DB::table('cab_facturations as cf')
            ->join('det_facturations as df', 'df.cab_id', '=', 'cf.id')
            ->where('cf.company_id', $companyId)
            ->where('df.paid', 0)
            ->whereRaw('(df.price_total - COALESCE(df.price_discount,0) - COALESCE(df.price_abone,0)) > 0')
            ->distinct()
            ->pluck('cf.user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
