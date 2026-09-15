<?php

namespace App\Services\Clientes;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Registro de clientes paginado en la base.
 *
 * Antes la pantalla traía todos los clientes activos de la empresa y filtraba,
 * contaba y paginaba en el navegador. Aquí se filtra por estado y búsqueda, y
 * cada estado se cuenta con la misma búsqueda: las pestañas no cambian al
 * elegir una.
 */
class ListaDeClientes
{
    /** Le falta IP de verdad: los PPPoE la reciben al conectarse. */
    private const SIN_IP = "(COALESCE(tabla_ips.ip, '') = '' AND COALESCE(user_data.connection_type, 'static') <> 'pppoe')";
    /** La columna nace en 1: sin fila marcada, el cliente recibe WhatsApp. */
    private const SIN_WA = 'COALESCE(user_data.whatsapp_enabled, 1) = 0';

    public function pagina(array $filtros): array
    {
        $base = $this->base(trim((string) ($filtros['q'] ?? '')), (int) ($filtros['cliente_id'] ?? 0));

        $c = (clone $base)->selectRaw("
            COUNT(*) AS todos,
            COALESCE(SUM(internet_status.name = 'ACTIVE'), 0) AS activos,
            COALESCE(SUM(internet_status.name <> 'ACTIVE'), 0) AS suspendidos,
            COALESCE(SUM(" . self::SIN_IP . "), 0) AS sin_ip,
            COALESCE(SUM(" . self::SIN_WA . "), 0) AS sin_wa
        ")->first();

        $conteos = [
            'todos'       => (int) $c->todos,
            'activos'     => (int) $c->activos,
            'suspendidos' => (int) $c->suspendidos,
            'sin_ip'      => (int) $c->sin_ip,
            'sin_wa'      => (int) $c->sin_wa,
        ];

        $query = clone $base;
        $estado = (string) ($filtros['estado'] ?? 'all');
        switch ($estado) {
            case 'active':    $query->where('internet_status.name', 'ACTIVE'); $total = $conteos['activos']; break;
            case 'suspended': $query->where('internet_status.name', '<>', 'ACTIVE'); $total = $conteos['suspendidos']; break;
            case 'noip':      $query->whereRaw(self::SIN_IP); $total = $conteos['sin_ip']; break;
            case 'nowa':      $query->whereRaw(self::SIN_WA); $total = $conteos['sin_wa']; break;
            default:          $total = $conteos['todos'];
        }

        $porPagina = min(100, max(5, (int) ($filtros['per_page'] ?? 12)));
        $ultima    = max(1, (int) ceil($total / $porPagina));
        $pagina    = min($ultima, max(1, (int) ($filtros['page'] ?? 1)));

        $items = $query->select(
            'users.id',
            'user_data.names',
            'user_data.lastname',
            'user_data.address',
            'user_data.dni',
            'user_data.email',
            'user_data.phone',
            'internet_status.name as internet_status',
            'internet_plans.plan_name',
            'cab_facturations.id as id_cab',
            'users.created_at as date_create',
            DB::raw("COALESCE(tabla_ips.ip, '') AS ip"),
            'users.username as alias',
            'user_data.router_id',
            'user_data.connection_type',
            'user_data.control_velocidad',
            DB::raw('COALESCE(user_data.whatsapp_enabled, 1) AS whatsapp_enabled')
        )
            // Los más recientes primero: el cliente recién creado queda a la vista.
            ->orderByDesc('users.id')
            ->forPage($pagina, $porPagina)
            ->get();

        return [
            'items'     => $items,
            'total'     => $total,
            'page'      => $pagina,
            'per_page'  => $porPagina,
            'last_page' => $ultima,
            'conteos'   => $conteos,
        ];
    }

    /** Clientes activos de la empresa de la sesión, con la búsqueda aplicada. */
    private function base(string $q, int $clienteId): Builder
    {
        $query = DB::table('users')
            ->join('user_data', 'users.id', 'user_data.user_id')
            ->join('internet_status', 'user_data.status_internet_id', 'internet_status.id')
            ->join('internet_plans', 'user_data.internet_plans_id', 'internet_plans.id')
            ->leftJoin('tabla_ips', 'user_data.ip_assignment_id', 'tabla_ips.id')
            ->join('cab_facturations', 'users.id', 'cab_facturations.user_id')
            ->where('user_data.active', 1)
            ->where('users.company_id', getSessionCompanyId());

        if ($clienteId) {
            $query->where('users.id', $clienteId);
        }

        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $query->where(function ($w) use ($like) {
                $w->where('user_data.names', 'like', $like)
                  ->orWhere('user_data.lastname', 'like', $like)
                  ->orWhereRaw("CONCAT_WS(' ', user_data.names, user_data.lastname) LIKE ?", [$like])
                  ->orWhere('user_data.dni', 'like', $like)
                  ->orWhere('user_data.phone', 'like', $like)
                  ->orWhere('user_data.email', 'like', $like)
                  ->orWhere('tabla_ips.ip', 'like', $like)
                  ->orWhere('users.username', 'like', $like);
            });
        }

        return $query;
    }
}
