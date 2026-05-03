<?php

namespace App\Repositories;

use App\Http\Requests\Egresos\CreateEgresosRequest;
use App\Models\Egresses;
use App\Repositories\Interfaces\EgresosRepositoryInterface;
use Illuminate\Support\Facades\DB;

class EgresosRepository implements EgresosRepositoryInterface
{
  /**
     * @param int $sponsor_id
     * @param CreateEgresosRequest $data
     * @return mixed
     */
    public function createEgresos(CreateEgresosRequest $data): mixed
    {
        return Egresses::create([
            'company_id'           => getSessionCompanyId(),
            'id_category_egresses' => 1,
            'concept'              => $data['concept'],
            'value'                => $data['value'],
            'user_id'              => $data['user_id'],
        ]);
    }

        /**
     * @return mixed
     */
    public function getEgresosAll(): mixed
    {
        return Egresses::where('company_id', getSessionCompanyId())->get();
    }

        /**
     * @return mixed
     */
    public function getCategory(): mixed
    {
        return Egresses::all();
    }


            /**
     * @return mixed
     */
    public function getPriceEgresseAll(): mixed
    {
        return $this->getPriceEgresseByRange(null, null);
    }

    public function getPriceEgresseByRange(?string $from, ?string $to): mixed
    {
        $companyId = getSessionCompanyId();

        $incomeQuery = DB::table('det_facturations as df')
            ->join('cab_facturations as cb', 'cb.id', '=', 'df.cab_id')
            ->join('users', 'users.id', '=', 'cb.user_id')
            ->where('users.company_id', $companyId)
            ->selectRaw('
                SUM(CASE WHEN df.abone != 1 AND df.paid = 1 THEN (df.price_total - df.price_discount) ELSE 0 END) AS valor_ingreso,
                SUM(CASE WHEN df.abone = 1  AND df.paid != 1 THEN df.price_abone ELSE 0 END) AS valor_abone
            ');

        if ($from) {
            $incomeQuery->whereDate('df.created_at', '>=', $from);
        }
        if ($to) {
            $incomeQuery->whereDate('df.created_at', '<=', $to);
        }

        $income = $incomeQuery->first();

        $egresosQuery = DB::table('egresses')->where('company_id', $companyId);
        if ($from) {
            $egresosQuery->whereDate('created_at', '>=', $from);
        }
        if ($to) {
            $egresosQuery->whereDate('created_at', '<=', $to);
        }
        $totalEgresses = $egresosQuery->sum('value');

        // Egresos por compras de inventario (entradas)
        $inventoryEgresses = DB::table('inventory_movements')
            ->where('company_id', $companyId)
            ->where('type', 'entrada');
        if ($from) {
            $inventoryEgresses->whereDate('created_at', '>=', $from);
        }
        if ($to) {
            $inventoryEgresses->whereDate('created_at', '<=', $to);
        }
        $totalInventoryEgresses = $inventoryEgresses->selectRaw('SUM(quantity * unit_price) as total')->value('total') ?? 0;

        $valorIngreso = $income->valor_ingreso ?? 0;
        $valorAbone   = $income->valor_abone   ?? 0;
        $totalSum     = $valorIngreso + $valorAbone;
        $totalGastos  = $totalEgresses + $totalInventoryEgresses;

        return (object)[
            'valor_ingreso'           => $valorIngreso,
            'valor_abone'             => $valorAbone,
            'total_sum'               => $totalSum,
            'total_egresses'          => $totalEgresses,
            'total_inventory_egresses'=> $totalInventoryEgresses,
            'total_gastos'            => $totalGastos,
            'net_value'               => $totalSum - $totalGastos,
        ];
    }

    // ── Egresos v2 ──────────────────────────────────────────────────────────

    public function getEgresosPaginated(?string $search, ?string $from, ?string $to, ?string $category, int $page, int $perPage): object
    {
        $companyId = getSessionCompanyId();
        $base = DB::table('egresses as e')
            ->leftJoin('payment_methods as pm', 'pm.id', '=', 'e.payment_method_id')
            ->where('e.company_id', $companyId);

        if ($search) {
            $base->where('e.concept', 'like', "%{$search}%");
        }
        if ($from)     $base->whereDate('e.created_at', '>=', $from);
        if ($to)       $base->whereDate('e.created_at', '<=', $to);
        if ($category) $base->where('e.category', $category);

        $total = (clone $base)->count();
        $items = (clone $base)
            ->select(['e.id', 'e.concept', 'e.category', 'e.value', 'e.user_id', 'e.created_at', DB::raw('pm.name as payment_method_name')])
            ->orderByDesc('created_at')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return (object)[
            'items'        => $items,
            'total'        => (int) $total,
            'per_page'     => $perPage,
            'current_page' => $page,
            'last_page'    => max(1, (int) ceil($total / $perPage)),
        ];
    }

    public function createEgresoV2(array $data): mixed
    {
        return Egresses::create([
            'company_id'           => getSessionCompanyId(),
            'id_category_egresses' => 1,
            'concept'              => $data['concept'],
            'category'             => $data['category'] ?? 'General',
            'value'                => $data['value'],
            'user_id'              => getSessionUserId(),
            'payment_method_id'    => $data['payment_method_id'] ?? null,
        ]);
    }

    public function updateEgreso(int $id, array $data): bool
    {
        $egreso = Egresses::where('id', $id)->where('company_id', getSessionCompanyId())->first();
        if (!$egreso) return false;
        $egreso->update([
            'concept'  => $data['concept']  ?? $egreso->concept,
            'category' => $data['category'] ?? $egreso->category,
            'value'    => $data['value']    ?? $egreso->value,
        ]);
        return true;
    }

    public function deleteEgreso(int $id): bool
    {
        return (bool) Egresses::where('id', $id)->where('company_id', getSessionCompanyId())->delete();
    }

    public function exportEgresos(?string $from, ?string $to): array
    {
        $query = DB::table('egresses')
            ->where('company_id', getSessionCompanyId())
            ->select(['id', 'concept', 'category', 'value', 'created_at'])
            ->orderByDesc('created_at');
        if ($from) $query->whereDate('created_at', '>=', $from);
        if ($to)   $query->whereDate('created_at', '<=', $to);
        return $query->get()->toArray();
    }

    public function getIngresosDetailed(?string $from, ?string $to): mixed
    {
        $companyId = getSessionCompanyId();

        $query = DB::table('det_facturations as df')
            ->join('cab_facturations as cb', 'cb.id', '=', 'df.cab_id')
            ->join('users', 'users.id', '=', 'cb.user_id')
            ->where('users.company_id', $companyId)
            ->where(function ($q) {
                $q->where(function ($q2) {
                    $q2->where('df.abone', '!=', 1)->where('df.paid', 1);
                })->orWhere(function ($q2) {
                    $q2->where('df.abone', 1)->where('df.paid', '!=', 1);
                });
            })
            ->select(
                'df.id',
                'df.number_facture',
                'df.date_facturation',
                'df.price_total',
                'df.price_abone',
                'df.price_discount',
                'df.abone',
                'df.paid',
                'df.created_at',
                'users.id as user_id',
                DB::raw("CONCAT(users.username) as cliente")
            )
            ->orderByDesc('df.created_at');

        if ($from) {
            $query->whereDate('df.created_at', '>=', $from);
        }
        if ($to) {
            $query->whereDate('df.created_at', '<=', $to);
        }

        return $query->get();
    }
}
