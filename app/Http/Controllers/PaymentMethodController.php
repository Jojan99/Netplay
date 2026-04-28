<?php

namespace App\Http\Controllers;

use App\Models\PaymentMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentMethodController extends Controller
{
    /** Lista todos los métodos de pago de la empresa */
    public function index(): JsonResponse
    {
        $methods = PaymentMethod::where('company_id', getSessionCompanyId())
            ->orderBy('name')
            ->get();

        return response()->json(['status' => 0, 'data' => $methods]);
    }

    /** Lista solo los métodos activos (para el modal de pago) */
    public function active(): JsonResponse
    {
        $methods = PaymentMethod::where('company_id', getSessionCompanyId())
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json(['status' => 0, 'data' => $methods]);
    }

    /** Crear método */
    public function store(Request $request): JsonResponse
    {
        $request->validate(['name' => 'required|string|max:80']);

        $method = PaymentMethod::create([
            'company_id' => getSessionCompanyId(),
            'name'       => trim($request->name),
            'active'     => true,
        ]);

        return response()->json(['status' => 0, 'data' => $method]);
    }

    /** Actualizar nombre */
    public function update(Request $request, int $id): JsonResponse
    {
        $request->validate(['name' => 'required|string|max:80']);

        $method = PaymentMethod::where('id', $id)
            ->where('company_id', getSessionCompanyId())
            ->firstOrFail();

        $method->update(['name' => trim($request->name)]);

        return response()->json(['status' => 0, 'data' => $method]);
    }

    /** Activar / desactivar */
    public function toggle(int $id): JsonResponse
    {
        $method = PaymentMethod::where('id', $id)
            ->where('company_id', getSessionCompanyId())
            ->firstOrFail();

        $method->update(['active' => !$method->active]);

        return response()->json(['status' => 0, 'data' => $method]);
    }

    /** Eliminar */
    public function destroy(int $id): JsonResponse
    {
        PaymentMethod::where('id', $id)
            ->where('company_id', getSessionCompanyId())
            ->delete();

        return response()->json(['status' => 0, 'data' => 'deleted']);
    }

    /**
     * Resumen de pagos agrupados por método para el período dado.
     * Query param: period = YYYY-MM  (default: mes actual)
     */
    public function summary(Request $request): JsonResponse
{
    $period = $request->query('period', now()->format('Y-m'));

    $rows = DB::table('payment_logs as pl')
        ->leftJoin('payment_methods as pm', 'pm.id', '=', 'pl.payment_method_id')
        ->where('pl.company_id', getSessionCompanyId())
        ->whereRaw("DATE_FORMAT(pl.created_at, '%Y-%m') = ?", [$period])
        ->selectRaw("
            COALESCE(pm.name, 'Sin método') as method_name,
            COUNT(*) as total_payments,
            COALESCE(SUM(pl.amount), 0) as total_amount
        ")
        ->groupBy('pl.payment_method_id', 'pm.name')
        ->orderByDesc('total_amount')
        ->get();

    return response()->json([
        'status' => 0,
        'data' => $rows
    ]);
}

/**
 * Lista de pagos individuales por método de pago.
 * Query params: method_name, period = YYYY-MM, page, per_page, search
 */
public function payments(Request $request): JsonResponse
{
    $methodName = $request->query('method_name');
    $period = $request->query('period', now()->format('Y-m'));
    $page = $request->query('page', 1);
    $perPage = $request->query('per_page', 15);
    $search = $request->query('search', '');

    $query = DB::table('payment_logs as pl')
        ->leftJoin('payment_methods as pm', 'pm.id', '=', 'pl.payment_method_id')
        ->leftJoin('det_facturations as det', 'det.id', '=', 'pl.det_facturation_id')
        ->where('pl.company_id', getSessionCompanyId())
        ->whereRaw("DATE_FORMAT(pl.created_at, '%Y-%m') = ?", [$period])
        ->selectRaw("
            pl.id,
            pl.client_name,
            pl.amount,
            pl.created_at as payment_date,
            det.id as invoice_id,
            COALESCE(pm.name, 'Sin método') as method_name
        ")
        ->orderByDesc('pl.created_at');

    if ($methodName) {
        $query->whereRaw("COALESCE(pm.name, 'Sin método') = ?", [$methodName]);
    }

    if ($search) {
        $query->where('pl.client_name', 'like', "%{$search}%");
    }

    $totalCount = $query->count();
    $totalSum = (clone $query)->sum('pl.amount') ?? 0;
    $payments = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

    return response()->json([
        'status' => 0,
        'data' => $payments,
        'total' => $totalCount,
        'total_sum' => $totalSum,
        'page' => $page,
        'per_page' => $perPage,
    ]);
}
}
