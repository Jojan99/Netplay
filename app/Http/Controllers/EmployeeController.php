<?php

namespace App\Http\Controllers;

use App\Repositories\Interfaces\EmployeeRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    public function __construct(private EmployeeRepositoryInterface $repo) {}

    private function ok(mixed $data, string $msg = 'ok'): JsonResponse
    {
        return response()->json(['status' => 0, 'message' => $msg, 'data' => $data]);
    }

    private function err(string $msg): JsonResponse
    {
        return response()->json(['status' => 1, 'message' => $msg, 'data' => null]);
    }

    // ── Empleados ─────────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        try {
            return $this->ok($this->repo->getAll($request->only('search', 'active')));
        } catch (\Throwable $e) {
            return $this->err($e->getMessage());
        }
    }

    public function availableStaff(): JsonResponse
    {
        try {
            return $this->ok($this->repo->getAvailableStaff());
        } catch (\Throwable $e) {
            return $this->err($e->getMessage());
        }
    }

    public function show(int $id): JsonResponse
    {
        try {
            return $this->ok($this->repo->getById($id));
        } catch (\Throwable $e) {
            return $this->err($e->getMessage());
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            return $this->ok($this->repo->create($request->only(
                'user_id','first_name','last_name','dni','email','phone','address','job_title','start_date','birthday','active'
            )), 'Empleado creado.');
        } catch (\Throwable $e) {
            return $this->err($e->getMessage());
        }
    }

    public function update(int $id, Request $request): JsonResponse
    {
        try {
            return $this->ok($this->repo->update($id, $request->only(
                'first_name','last_name','dni','email','phone','address','job_title','start_date','birthday','active'
            )), 'Empleado actualizado.');
        } catch (\Throwable $e) {
            return $this->err($e->getMessage());
        }
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            $this->repo->delete($id);
            return $this->ok(null, 'Empleado eliminado.');
        } catch (\Throwable $e) {
            return $this->err($e->getMessage());
        }
    }

    // ── Contrato laboral ──────────────────────────────────────────────────────

    public function upsertLaborContract(int $id, Request $request): JsonResponse
    {
        try {
            return $this->ok($this->repo->upsertLaborContract($id, $request->only(
                'type','duration_months','salary','start_date','end_date'
            )), 'Contrato guardado.');
        } catch (\Throwable $e) {
            return $this->err($e->getMessage());
        }
    }

    // ── Afiliaciones ──────────────────────────────────────────────────────────

    public function upsertAffiliation(int $id, Request $request): JsonResponse
    {
        try {
            return $this->ok($this->repo->upsertAffiliation($id, $request->only(
                'arl','eps','pension_fund','compensation_fund'
            )), 'Afiliaciones guardadas.');
        } catch (\Throwable $e) {
            return $this->err($e->getMessage());
        }
    }

    // ── Cuenta bancaria ───────────────────────────────────────────────────────

    public function upsertBankAccount(int $id, Request $request): JsonResponse
    {
        try {
            return $this->ok($this->repo->upsertBankAccount($id, $request->only(
                'bank_name','account_type','account_number'
            )), 'Cuenta bancaria guardada.');
        } catch (\Throwable $e) {
            return $this->err($e->getMessage());
        }
    }

    // ── Dotaciones ────────────────────────────────────────────────────────────

    public function getEquipment(int $id): JsonResponse
    {
        try { return $this->ok($this->repo->getEquipment($id)); }
        catch (\Throwable $e) { return $this->err($e->getMessage()); }
    }

    public function createEquipment(int $id, Request $request): JsonResponse
    {
        try {
            return $this->ok($this->repo->createEquipment($id, $request->only(
                'name','description','serial','condition','assigned_at','returned_at'
            )), 'Dotación registrada.');
        } catch (\Throwable $e) { return $this->err($e->getMessage()); }
    }

    public function updateEquipment(int $id, int $eqId, Request $request): JsonResponse
    {
        try {
            return $this->ok($this->repo->updateEquipment($id, $eqId, $request->only(
                'name','description','serial','condition','assigned_at','returned_at'
            )), 'Dotación actualizada.');
        } catch (\Throwable $e) { return $this->err($e->getMessage()); }
    }

    public function deleteEquipment(int $id, int $eqId): JsonResponse
    {
        try { $this->repo->deleteEquipment($id, $eqId); return $this->ok(null, 'Dotación eliminada.'); }
        catch (\Throwable $e) { return $this->err($e->getMessage()); }
    }

    // ── Descargos ─────────────────────────────────────────────────────────────

    public function getDisciplinary(int $id): JsonResponse
    {
        try { return $this->ok($this->repo->getDisciplinary($id)); }
        catch (\Throwable $e) { return $this->err($e->getMessage()); }
    }

    public function createDisciplinary(int $id, Request $request): JsonResponse
    {
        try {
            return $this->ok($this->repo->createDisciplinary($id, $request->only(
                'incident_date','reason','description','resolution'
            )), 'Descargo registrado.');
        } catch (\Throwable $e) { return $this->err($e->getMessage()); }
    }

    public function updateDisciplinary(int $id, int $recId, Request $request): JsonResponse
    {
        try {
            return $this->ok($this->repo->updateDisciplinary($id, $recId, $request->only(
                'incident_date','reason','description','resolution'
            )), 'Descargo actualizado.');
        } catch (\Throwable $e) { return $this->err($e->getMessage()); }
    }

    public function deleteDisciplinary(int $id, int $recId): JsonResponse
    {
        try { $this->repo->deleteDisciplinary($id, $recId); return $this->ok(null, 'Descargo eliminado.'); }
        catch (\Throwable $e) { return $this->err($e->getMessage()); }
    }

    // ── Nómina ────────────────────────────────────────────────────────────────

    public function getPayrolls(int $id): JsonResponse
    {
        try { return $this->ok($this->repo->getPayrolls($id)); }
        catch (\Throwable $e) { return $this->err($e->getMessage()); }
    }

    public function createPayroll(int $id, Request $request): JsonResponse
    {
        try {
            return $this->ok($this->repo->createPayroll($id, $request->only(
                'period','base_salary','bonuses','deductions','total_paid','payment_date','payment_method','notes'
            )), 'Pago de nómina registrado.');
        } catch (\Throwable $e) { return $this->err($e->getMessage()); }
    }

    public function updatePayroll(int $id, int $payId, Request $request): JsonResponse
    {
        try {
            return $this->ok($this->repo->updatePayroll($id, $payId, $request->only(
                'period','base_salary','bonuses','deductions','total_paid','payment_date','payment_method','notes'
            )), 'Nómina actualizada.');
        } catch (\Throwable $e) { return $this->err($e->getMessage()); }
    }

    public function deletePayroll(int $id, int $payId): JsonResponse
    {
        try { $this->repo->deletePayroll($id, $payId); return $this->ok(null, 'Nómina eliminada.'); }
        catch (\Throwable $e) { return $this->err($e->getMessage()); }
    }
}
