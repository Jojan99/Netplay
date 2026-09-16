<?php

namespace App\Http\Controllers;

use App\Models\InternetPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InternetPlanController extends Controller
{
    private function companyId(): int
    {
        return getSessionCompanyId();
    }

    private function ok(mixed $data, string $msg = 'ok'): JsonResponse
    {
        return response()->json(['status' => 0, 'message' => $msg, 'data' => $data]);
    }

    private function err(string $msg, int $code = 200): JsonResponse
    {
        return response()->json(['status' => 1, 'message' => $msg, 'data' => null], $code);
    }

    /**
     * GET /api/plans
     * Lista planes de la empresa con conteo de clientes activos.
     */
    public function index(): JsonResponse
    {
        try {
            $plans = InternetPlan::where('internet_plans.company_id', $this->companyId())
                ->leftJoin('user_data', 'internet_plans.id', '=', 'user_data.internet_plans_id')
                ->selectRaw('internet_plans.*, COUNT(user_data.id) as clients_count')
                ->groupBy('internet_plans.id')
                ->orderBy('internet_plans.plan_name')
                ->get();

            return $this->ok($plans);
        } catch (\Throwable $e) {
            return $this->err($e->getMessage());
        }
    }

    /**
     * Lo que se guarda de un plan, ya revisado.
     *
     * La descripción es opcional, pero la columna no acepta nulos: sin esto,
     * crear un plan sin descripción devolvía el error de SQL tal cual.
     */
    private function datosDelPlan(Request $request, bool $parcial = false): array
    {
        $req = $parcial ? 'sometimes|required' : 'required';

        $datos = $request->validate([
            'plan_name'      => "{$req}|string|max:255",
            'download_speed' => "{$req}|numeric|min:0",
            'upload_speed'   => "{$req}|numeric|min:0",
            'monthly_price'  => "{$req}|numeric|min:0",
            'description'    => 'nullable|string|max:255',
            'type'           => 'nullable|string|max:255',
            'active'         => 'nullable|boolean',
        ], [
            'plan_name.required'      => 'Falta el nombre del plan.',
            'download_speed.required' => 'Falta la velocidad de bajada.',
            'upload_speed.required'   => 'Falta la velocidad de subida.',
            'monthly_price.required'  => 'Falta el precio mensual.',
            '*.numeric'               => 'Las velocidades y el precio tienen que ser números.',
        ]);

        if (array_key_exists('description', $datos) || !$parcial) {
            $datos['description'] = trim((string) ($datos['description'] ?? ''));
        }

        if (array_key_exists('type', $datos) && !$datos['type']) {
            unset($datos['type']);
        }

        return $datos;
    }

    /**
     * POST /api/plans
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $datos = $this->datosDelPlan($request);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->err(collect($e->errors())->flatten()->first());
        }

        try {
            $plan = InternetPlan::create($datos + ['company_id' => $this->companyId()]);

            return $this->ok($plan, 'Plan creado correctamente.');
        } catch (\Throwable $e) {
            return $this->err($e->getMessage());
        }
    }

    /**
     * PUT /api/plans/{id}
     */
    public function update(int $id, Request $request): JsonResponse
    {
        try {
            $plan = InternetPlan::where('id', $id)
                ->where('company_id', $this->companyId())
                ->firstOrFail();

            $plan->update($this->datosDelPlan($request, true));

            return $this->ok($plan->fresh(), 'Plan actualizado.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->err(collect($e->errors())->flatten()->first());
        } catch (\Throwable $e) {
            return $this->err($e->getMessage());
        }
    }

    /**
     * DELETE /api/plans/{id}
     * Solo permite eliminar si no tiene clientes asignados.
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $plan = InternetPlan::where('id', $id)
                ->where('company_id', $this->companyId())
                ->firstOrFail();

            $clientsCount = $plan->clients()->count();
            if ($clientsCount > 0) {
                return $this->err("No se puede eliminar: tiene {$clientsCount} cliente(s) asignado(s). Cambia su plan primero.");
            }

            $plan->delete();
            return $this->ok(null, 'Plan eliminado.');
        } catch (\Throwable $e) {
            return $this->err($e->getMessage());
        }
    }

    /**
     * PATCH /api/plans/{id}/toggle
     * Activa o desactiva un plan rápidamente.
     */
    public function toggle(int $id): JsonResponse
    {
        try {
            $plan = InternetPlan::where('id', $id)
                ->where('company_id', $this->companyId())
                ->firstOrFail();

            $plan->update(['active' => !$plan->active]);
            $state = $plan->active ? 'activado' : 'desactivado';

            return $this->ok($plan->fresh(), "Plan {$state}.");
        } catch (\Throwable $e) {
            return $this->err($e->getMessage());
        }
    }
}
