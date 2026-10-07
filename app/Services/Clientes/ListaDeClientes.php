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
    /** Los que cobran por la pasarela: la marca vive en la factura. */
    private const CON_FE = 'COALESCE(cab_facturations.billing_electronic, 0) = 1';
    /** Mandó un comprobante que todavía espera en la auditoría (la burbuja de pago). */
    private const CON_PAGO = "EXISTS (SELECT 1 FROM payment_proofs pp WHERE pp.user_id = users.id AND pp.company_id = users.company_id AND pp.status = 'pending')";
    /** Sin facturas por pagar: el mismo criterio con el que AutoSuspendService reactiva solo. */
    private const AL_DIA = 'NOT EXISTS (SELECT 1 FROM det_facturations df WHERE df.cab_id = cab_facturations.id AND df.paid = 0 AND df.anulada_en IS NULL AND df.abone <> 1)';
    /**
     * Pagó y sigue suspendido: está al día, o mandó un comprobante que espera en la
     * auditoría. Es el cliente que escribe «ya pagué y no tengo internet». Los que un
     * operador suspendió con «no reactivar automáticamente» no cuentan: están así a propósito.
     */
    private const PAGO_SUSPENDIDO = "(internet_status.name <> 'ACTIVE' AND COALESCE(user_data.no_reactivar_auto, 0) = 0 AND ("
        . self::AL_DIA . ' OR ' . self::CON_PAGO . '))';

    public function pagina(array $filtros): array
    {
        $base = $this->base(trim((string) ($filtros['q'] ?? '')), (int) ($filtros['cliente_id'] ?? 0));

        $c = (clone $base)->selectRaw("
            COUNT(*) AS todos,
            COALESCE(SUM(internet_status.name = 'ACTIVE'), 0) AS activos,
            COALESCE(SUM(internet_status.name <> 'ACTIVE'), 0) AS suspendidos,
            COALESCE(SUM(" . self::SIN_IP . "), 0) AS sin_ip,
            COALESCE(SUM(" . self::SIN_WA . "), 0) AS sin_wa,
            COALESCE(SUM(" . self::CON_FE . "), 0) AS con_fe,
            COALESCE(SUM(" . self::CON_PAGO . "), 0) AS con_pago,
            COALESCE(SUM(" . self::PAGO_SUSPENDIDO . "), 0) AS pago_suspendido
        ")->first();

        $conteos = [
            'todos'       => (int) $c->todos,
            'activos'     => (int) $c->activos,
            'suspendidos' => (int) $c->suspendidos,
            'sin_ip'      => (int) $c->sin_ip,
            'sin_wa'      => (int) $c->sin_wa,
            'con_fe'      => (int) $c->con_fe,
            'con_pago'    => (int) $c->con_pago,
            'pago_suspendido' => (int) $c->pago_suspendido,
        ];

        $query = clone $base;
        $estado = (string) ($filtros['estado'] ?? 'all');
        switch ($estado) {
            case 'active':    $query->where('internet_status.name', 'ACTIVE'); $total = $conteos['activos']; break;
            case 'suspended': $query->where('internet_status.name', '<>', 'ACTIVE'); $total = $conteos['suspendidos']; break;
            case 'noip':      $query->whereRaw(self::SIN_IP); $total = $conteos['sin_ip']; break;
            case 'nowa':      $query->whereRaw(self::SIN_WA); $total = $conteos['sin_wa']; break;
            case 'fe':        $query->whereRaw(self::CON_FE); $total = $conteos['con_fe']; break;
            case 'pago':      $query->whereRaw(self::CON_PAGO); $total = $conteos['con_pago']; break;
            case 'pagosusp':  $query->whereRaw(self::PAGO_SUSPENDIDO); $total = $conteos['pago_suspendido']; break;
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
            DB::raw('COALESCE(user_data.whatsapp_enabled, 1) AS whatsapp_enabled'),
            DB::raw('COALESCE(cab_facturations.billing_electronic, 0) AS billing_electronic'),
            // Por qué está en «Pagó y sigue suspendido»: al día se reactiva ya; con
            // comprobante, primero hay que aprobarlo en la auditoría.
            DB::raw('(' . self::AL_DIA . ') AS al_dia')
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
