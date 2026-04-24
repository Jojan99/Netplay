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
    $rows = DB::table('payment_logs as pl')
        ->leftJoin('payment_methods as pm', 'pm.id', '=', 'pl.payment_method_id')
        ->where('pl.company_id', getSessionCompanyId())
        ->selectRaw("
            COALESCE(pm.name, 'Sin método') as method_name,
            COUNT(*) as total_payments,
            SUM(pl.amount) as total_amount
        ")
        ->groupBy('pl.payment_method_id', 'pm.name')
        ->orderByDesc('total_amount')
        ->get();

    return response()->json([
        'status' => 0,
        'data' => $rows
    ]);
}
}
