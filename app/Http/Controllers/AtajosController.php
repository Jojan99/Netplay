<?php

namespace App\Http\Controllers;

use App\Services\ClientStatementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Datos para el buscador (Ctrl+K) y las ventanas rápidas del panel.
 *
 * Todo sale filtrado por la empresa en sesión: el buscador recibe texto libre
 * (nombre, cédula, teléfono, IP o serial de la ONT) y la ficha es lo mínimo
 * para atender una llamada sin abrir el módulo de clientes.
 */
class AtajosController extends Controller
{
    private const LIMITE = 8;

    public function buscar(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $empresa = (int) getSessionCompanyId();

        if (mb_strlen($q) < 2 || !$empresa) {
            return standardApiReponse('ok', ['clientes' => []], 0, JsonResponse::HTTP_OK);
        }

        $like = '%' . addcslashes($q, '%_\\') . '%';
        $digitos = preg_replace('/\D/', '', $q);

        $clientes = $this->baseDeClientes($empresa)
            ->where(function ($w) use ($like, $digitos, $q) {
                $w->where(DB::raw("CONCAT_WS(' ', ud.names, ud.lastname)"), 'like', $like)
                  ->orWhere('ud.dni', 'like', $like)
                  ->orWhere('u.username', 'like', $like)
                  ->orWhere('ip.ip', 'like', $like)
                  ->orWhere('ont.serial', 'like', $like);
                // El teléfono se guarda con o sin espacios y prefijo.
                if (strlen($digitos) >= 6) {
                    $w->orWhere(DB::raw("REPLACE(REPLACE(ud.phone, ' ', ''), '+', '')"), 'like', '%' . $digitos . '%');
                }
            })
            ->orderBy('ud.names')
            ->limit(self::LIMITE)
            ->get()
            ->map(fn($c) => $this->fila($c))
            ->values();

        return standardApiReponse('ok', ['clientes' => $clientes], 0, JsonResponse::HTTP_OK);
    }

    /** Ficha corta del cliente; la deuda sólo para quien ve finanzas. */
    public function cliente(int $userId, ClientStatementService $estados): JsonResponse
    {
        $empresa = (int) getSessionCompanyId();
        $c = $this->baseDeClientes($empresa)->where('ud.user_id', $userId)->first();

        if (!$c) {
            return standardApiReponse('Cliente no encontrado', null, 1, JsonResponse::HTTP_NOT_FOUND);
        }

        $data = $this->fila($c) + [
            'direccion' => $c->address,
            'email'     => $c->email,
            'conexion'  => $c->connection_type,
            'deuda'     => null,
        ];

        if ($this->veFinanzas()) {
            $estado = $estados->build($userId, $empresa);
            $pendientes = array_values(array_filter($estado['invoices'] ?? [], fn($f) => !$f['paid']));

            $data['deuda'] = [
                'saldo'     => round(array_sum(array_column($pendientes, 'balance')), 2),
                'vencidas'  => count(array_filter($pendientes, fn($f) => $f['status'] === 'overdue')),
                'facturas'  => array_map(fn($f) => [
                    'id'       => $f['id'],
                    'numero'   => $f['number'],
                    'vence'    => $f['due_date'],
                    'total'    => $f['total'],
                    'saldo'    => $f['balance'],
                    'estado'   => $f['status'],
                    'pdf_url'  => $f['pdf_url'],
                ], array_slice($pendientes, 0, 12)),
                'enlace'    => ClientStatementService::urlFor($userId),
            ];
        }

        return standardApiReponse('ok', $data, 0, JsonResponse::HTTP_OK);
    }

    private function baseDeClientes(int $empresa)
    {
        // La primera ONT del cliente dentro de una OLT de la misma empresa.
        $ontDelCliente = DB::table('olt_onts as o')
            ->join('olt_admins as oa', 'oa.id', '=', 'o.olt_id')
            ->where('oa.company_id', $empresa)
            ->groupBy('o.user_data_id')
            ->select('o.user_data_id', DB::raw('MIN(o.id) as id'));

        return DB::table('user_data as ud')
            ->join('users as u', 'u.id', '=', 'ud.user_id')
            ->leftJoin('internet_plans as pl', 'pl.id', '=', 'ud.internet_plans_id')
            ->leftJoin('internet_status as st', 'st.id', '=', 'ud.status_internet_id')
            ->leftJoin('tabla_ips as ip', function ($j) use ($empresa) {
                $j->on('ip.id', '=', 'ud.ip_assignment_id')->where('ip.company_id', $empresa);
            })
            ->leftJoinSub($ontDelCliente, 'primera', 'primera.user_data_id', '=', 'ud.user_id')
            ->leftJoin('olt_onts as ont', 'ont.id', '=', 'primera.id')
            ->leftJoin('olt_admins as olt', 'olt.id', '=', 'ont.olt_id')
            ->where('u.company_id', $empresa)
            ->select(
                'ud.user_id', 'ud.names', 'ud.lastname', 'ud.dni', 'ud.phone', 'ud.address', 'ud.email',
                'ud.connection_type', 'ud.pppoe_user', 'pl.plan_name', 'st.name as estado', 'ip.ip',
                'ont.fsp', 'ont.ont_id', 'ont.serial', 'ont.status as ont_estado', 'olt.id as olt_id', 'olt.name as olt_nombre'
            );
    }

    private function fila(object $c): array
    {
        return [
            'id'       => (int) $c->user_id,
            'nombre'   => trim($c->names . ' ' . $c->lastname),
            'dni'      => $c->dni,
            'telefono' => $c->phone,
            'plan'     => $c->plan_name,
            'estado'   => $c->estado,
            'ip'       => $c->ip ?: null,
            'pppoe'    => $c->connection_type === 'pppoe' ? $c->pppoe_user : null,
            'ont'      => $c->fsp === null ? null : [
                'olt_id' => (int) $c->olt_id,
                'olt'    => $c->olt_nombre,
                'fsp'    => $c->fsp,
                'ont_id' => (int) $c->ont_id,
                'serial' => $c->serial,
                'estado' => $c->ont_estado,
            ],
        ];
    }

    private function veFinanzas(): bool
    {
        $perfil = strtolower((string) DB::table('profiles')->where('id', getSessionUserProfileId())->value('name'));
        return in_array($perfil, ['admin', 'contador'], true);
    }
}
