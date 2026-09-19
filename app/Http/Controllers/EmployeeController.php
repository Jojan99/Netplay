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

    /**
     * Traduce el error técnico a algo que se pueda mostrar en pantalla: antes
     * salía el texto crudo de MySQL, en inglés y con la consulta adentro.
     */
    private function enCristiano(\Throwable $e): string
    {
        if ($e instanceof \Illuminate\Validation\ValidationException) {
            return (string) collect($e->errors())->flatten()->first();
        }

        if ($e instanceof \Illuminate\Database\QueryException) {
            $texto  = $e->getMessage();
            $codigo = (string) ($e->errorInfo[1] ?? '');

            if ($codigo === '1062') {
                if (str_contains($texto, 'dni'))   return 'Ya hay un empleado con esa cédula en la empresa.';
                if (str_contains($texto, 'email')) return 'Ya hay un empleado con ese correo en la empresa.';
                return 'Ese dato ya está registrado en otro empleado.';
            }

            if ($codigo === '1048') {
                return 'Faltan datos obligatorios: revisá nombre, apellido y cédula.';
            }

            if ($codigo === '1452') {
                return 'El dato que elegiste ya no existe (usuario, cargo o empleado). Recargá la pantalla e intentá de nuevo.';
            }

            \Illuminate\Support\Facades\Log::error('Empleados: ' . $texto);

            return 'No se pudo guardar. Revisá los datos e intentá de nuevo.';
        }

        if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
            return 'No se encontró el registro: puede que otra persona lo haya borrado.';
        }

        \Illuminate\Support\Facades\Log::error('Empleados: ' . $e->getMessage());

        return 'Ocurrió un error al procesar la solicitud. Intentá de nuevo; si sigue igual, avisale al soporte de Netvula.';
    }

    // ── Empleados ─────────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        try {
            return $this->ok($this->repo->getAll($request->only('search', 'active')));
        } catch (\Throwable $e) {
            return $this->err($this->enCristiano($e));
        }
    }

    public function availableStaff(): JsonResponse
    {
        try {
            return $this->ok($this->repo->getAvailableStaff());
        } catch (\Throwable $e) {
            return $this->err($this->enCristiano($e));
        }
    }

    public function show($id): JsonResponse
    {
        try {
            return $this->ok($this->repo->getById($id));
        } catch (\Throwable $e) {
            return $this->err($this->enCristiano($e));
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            // El usuario vinculado tiene que ser de la misma empresa
            if ($request->filled('user_id')) {
                $propio = \Illuminate\Support\Facades\DB::table('users')
                    ->where('id', $request->input('user_id'))
                    ->where('company_id', getSessionCompanyId() ?: (\Tymon\JWTAuth\Facades\JWTAuth::user()->company_id ?? 0))
                    ->exists();
                if (!$propio) {
                    return $this->err('El usuario no pertenece a la empresa.');
                }
            }

            return $this->ok($this->repo->create($request->only(
                'user_id','first_name','last_name','dni','email','phone','address','job_title','start_date','birthday','active'
            )), 'Empleado creado.');
        } catch (\Throwable $e) {
            return $this->err($this->enCristiano($e));
        }
    }

    public function update($id, Request $request): JsonResponse
    {
        try {
            return $this->ok($this->repo->update($id, $request->only(
                'first_name','last_name','dni','email','phone','address','job_title','start_date','birthday','active'
            )), 'Empleado actualizado.');
        } catch (\Throwable $e) {
            return $this->err($this->enCristiano($e));
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $this->repo->delete($id);
            return $this->ok(null, 'Empleado eliminado.');
        } catch (\Throwable $e) {
            return $this->err($this->enCristiano($e));
        }
    }

    // ── Contrato laboral ──────────────────────────────────────────────────────

    public function upsertLaborContract($id, Request $request): JsonResponse
    {
        try {
            return $this->ok($this->repo->upsertLaborContract($id, $request->only(
                'type','duration_months','salary','start_date','end_date'
            )), 'Contrato guardado.');
        } catch (\Throwable $e) {
            return $this->err($this->enCristiano($e));
        }
    }

    // ── Afiliaciones ──────────────────────────────────────────────────────────

    public function upsertAffiliation($id, Request $request): JsonResponse
    {
        try {
            return $this->ok($this->repo->upsertAffiliation($id, $request->only(
                'arl','eps','pension_fund','compensation_fund'
            )), 'Afiliaciones guardadas.');
        } catch (\Throwable $e) {
            return $this->err($this->enCristiano($e));
        }
    }

    // ── Cuenta bancaria ───────────────────────────────────────────────────────

    public function upsertBankAccount($id, Request $request): JsonResponse
    {
        try {
            return $this->ok($this->repo->upsertBankAccount($id, $request->only(
                'bank_name','account_type','account_number'
            )), 'Cuenta bancaria guardada.');
        } catch (\Throwable $e) {
            return $this->err($this->enCristiano($e));
        }
    }

    // ── Dotaciones ────────────────────────────────────────────────────────────

    public function getEquipment($id): JsonResponse
    {
        try { return $this->ok($this->repo->getEquipment($id)); }
        catch (\Throwable $e) { return $this->err($this->enCristiano($e)); }
    }

    public function createEquipment($id, Request $request): JsonResponse
    {
        try {
            return $this->ok($this->repo->createEquipment($id, $request->only(
                'name','description','serial','condition','assigned_at','returned_at'
            )), 'Dotación registrada.');
        } catch (\Throwable $e) { return $this->err($this->enCristiano($e)); }
    }

    public function updateEquipment($id, $eqId, Request $request): JsonResponse
    {
        try {
            return $this->ok($this->repo->updateEquipment($id, $eqId, $request->only(
                'name','description','serial','condition','assigned_at','returned_at'
            )), 'Dotación actualizada.');
        } catch (\Throwable $e) { return $this->err($this->enCristiano($e)); }
    }

    public function deleteEquipment($id, $eqId): JsonResponse
    {
        try { $this->repo->deleteEquipment($id, $eqId); return $this->ok(null, 'Dotación eliminada.'); }
        catch (\Throwable $e) { return $this->err($this->enCristiano($e)); }
    }

    // ── Descargos ─────────────────────────────────────────────────────────────

    public function getDisciplinary($id): JsonResponse
    {
        try { return $this->ok($this->repo->getDisciplinary($id)); }
        catch (\Throwable $e) { return $this->err($this->enCristiano($e)); }
    }

    public function createDisciplinary($id, Request $request): JsonResponse
    {
        try {
            return $this->ok($this->repo->createDisciplinary($id, $request->only(
                'incident_date','reason','description','resolution'
            )), 'Descargo registrado.');
        } catch (\Throwable $e) { return $this->err($this->enCristiano($e)); }
    }

    public function updateDisciplinary($id, $recId, Request $request): JsonResponse
    {
        try {
            return $this->ok($this->repo->updateDisciplinary($id, $recId, $request->only(
                'incident_date','reason','description','resolution'
            )), 'Descargo actualizado.');
        } catch (\Throwable $e) { return $this->err($this->enCristiano($e)); }
    }

    public function deleteDisciplinary($id, $recId): JsonResponse
    {
        try { $this->repo->deleteDisciplinary($id, $recId); return $this->ok(null, 'Descargo eliminado.'); }
        catch (\Throwable $e) { return $this->err($this->enCristiano($e)); }
    }

    // ── Nómina ────────────────────────────────────────────────────────────────

    public function getPayrolls($id): JsonResponse
    {
        try { return $this->ok($this->repo->getPayrolls($id)); }
        catch (\Throwable $e) { return $this->err($this->enCristiano($e)); }
    }

    public function createPayroll($id, Request $request): JsonResponse
    {
        try {
            return $this->ok($this->repo->createPayroll($id, $request->only(
                'period','base_salary','bonuses','deductions','total_paid','payment_date','payment_method','notes'
            )), 'Pago de nómina registrado.');
        } catch (\Throwable $e) { return $this->err($this->enCristiano($e)); }
    }

    public function updatePayroll($id, $payId, Request $request): JsonResponse
    {
        try {
            return $this->ok($this->repo->updatePayroll($id, $payId, $request->only(
                'period','base_salary','bonuses','deductions','total_paid','payment_date','payment_method','notes'
            )), 'Nómina actualizada.');
        } catch (\Throwable $e) { return $this->err($this->enCristiano($e)); }
    }

    public function deletePayroll($id, $payId): JsonResponse
    {
        try { $this->repo->deletePayroll($id, $payId); return $this->ok(null, 'Nómina eliminada.'); }
        catch (\Throwable $e) { return $this->err($this->enCristiano($e)); }
    }

    // ── Ubicación en tiempo real ───────────────────────────────────────

    public function updateLocation($id, Request $request): JsonResponse
    {
        try {
            $request->validate([
                'latitude'  => 'required|numeric|between:-90,90',
                'longitude' => 'required|numeric|between:-180,180',
            ], [
                'latitude.required'  => 'Falta la latitud de la ubicación.',
                'longitude.required' => 'Falta la longitud de la ubicación.',
                'latitude.numeric'   => 'La latitud no es válida.',
                'longitude.numeric'  => 'La longitud no es válida.',
                'latitude.between'   => 'La latitud está fuera de rango.',
                'longitude.between'  => 'La longitud está fuera de rango.',
            ]);

            return $this->ok(
                $this->repo->updateLocation((int) $id, (float) $request->latitude, (float) $request->longitude),
                'Ubicación actualizada.'
            );
        } catch (\Throwable $e) {
            return $this->err($this->enCristiano($e));
        }
    }

    public function getTechnicianLocations(Request $request): JsonResponse
    {
        try {
            return $this->ok($this->repo->getTechnicianLocations(
                $request->only('job_title', 'last_update_minutes')
            ));
        } catch (\Throwable $e) {
            return $this->err($this->enCristiano($e));
        }
    }

    /**
     * Actualizar ubicación del empleado autenticado vía JWT (PUT /employees/my-location)
     */
    public function updateMyLocation(Request $request): JsonResponse
    {
        try {
            $user = \Tymon\JWTAuth\Facades\JWTAuth::user();
            if (!$user) {
                return $this->err('No autenticado');
            }

            $employee = \App\Models\Employee::where('user_id', $user->id)
                ->where('company_id', $user->company_id)
                ->first();

            if (!$employee) {
                return $this->err('No se encontró empleado asociado a este usuario');
            }

            $request->validate([
                'latitude'  => 'required|numeric|between:-90,90',
                'longitude' => 'required|numeric|between:-180,180',
            ], [
                'latitude.required'  => 'Falta la latitud de la ubicación.',
                'longitude.required' => 'Falta la longitud de la ubicación.',
                'latitude.numeric'   => 'La latitud no es válida.',
                'longitude.numeric'  => 'La longitud no es válida.',
                'latitude.between'   => 'La latitud está fuera de rango.',
                'longitude.between'  => 'La longitud está fuera de rango.',
            ]);

            $lat = (float) $request->input('latitude');
            $lng = (float) $request->input('longitude');

            $result = $this->repo->updateLocation((int) $employee->id, $lat, $lng);

            // Emitir evento Pusher para actualización en tiempo real
            event(new \App\Events\TechnicianLocationUpdated(
                (int) $employee->id,
                $employee->first_name,
                $employee->last_name,
                $employee->job_title,
                $lat,
                $lng
            ));

            return $this->ok($result, 'Ubicación actualizada.');
        } catch (\Throwable $e) {
            return $this->err($this->enCristiano($e));
        }
    }
}
