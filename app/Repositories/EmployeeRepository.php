<?php

namespace App\Repositories;

use App\Models\Employee;
use App\Models\EmployeeAffiliation;
use App\Models\EmployeeBankAccount;
use App\Models\EmployeeDisciplinaryRecord;
use App\Models\EmployeeEquipment;
use App\Models\EmployeeLaborContract;
use App\Models\EmployeePayroll;
use App\Repositories\Interfaces\EmployeeRepositoryInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class EmployeeRepository implements EmployeeRepositoryInterface
{
    private function companyId(): int
    {
        $id = getSessionCompanyId();
        // Si es null, usar el company_id del primer empleado o 1 por defecto
        return $id ?? \App\Models\Employee::first()?->company_id ?? 1;
    }

    private function findEmployee(int $id): Employee
    {
        return Employee::where('id', $id)->where('company_id', $this->companyId())->firstOrFail();
    }

    public function getAll(array $filters = []): mixed
    {
        $q = Employee::where('employees.company_id', $this->companyId())
            ->leftJoin('users',     'employees.user_id', '=', 'users.id')
            ->leftJoin('user_data', 'users.id',          '=', 'user_data.user_id')
            ->leftJoin('profiles',  'users.profile_id',  '=', 'profiles.id')
            ->select(
                'employees.*',
                'profiles.name as staff_profile'
            );

        if (!empty($filters['search'])) {
            $s = $filters['search'];
            $q->where(fn($w) => $w
                ->where('employees.first_name', 'like', "%{$s}%")
                ->orWhere('employees.last_name',  'like', "%{$s}%")
                ->orWhere('employees.dni',        'like', "%{$s}%")
                ->orWhere('employees.job_title',  'like', "%{$s}%")
            );
        }
        if (isset($filters['active'])) {
            $q->where('employees.active', (bool) $filters['active']);
        }

        return $q->orderByDesc('employees.created_at')->get();
    }

    /**
     * Retorna usuarios de staff (no USER) de la empresa que aún no tienen
     * un registro en la tabla employees.
     */
    public function getAvailableStaff(): mixed
    {
        $companyId = $this->companyId();
        $linked    = Employee::where('company_id', $companyId)->whereNotNull('user_id')->pluck('user_id');

        return DB::table('users')
            ->join('user_data', 'users.id', '=', 'user_data.user_id')
            ->join('profiles',  'users.profile_id', '=', 'profiles.id')
            ->where('users.company_id', $companyId)
            ->where('profiles.name', '!=', 'USER')
            ->whereNotIn('users.id', $linked)
            ->select(
                'users.id',
                'user_data.names',
                'user_data.lastname',
                'user_data.email',
                'user_data.phone',
                'user_data.dni',
                'profiles.name as profile'
            )
            ->orderBy('user_data.names')
            ->get();
    }

    public function getById(int $id): mixed
    {
        return Employee::where('id', $id)
            ->where('company_id', $this->companyId())
            ->with(['laborContract', 'affiliation', 'bankAccount', 'equipment', 'disciplinary', 'payrolls'])
            ->firstOrFail();
    }

    public function create(array $data): mixed
    {
        return Employee::create(array_merge($data, ['company_id' => $this->companyId()]));
    }

    public function update(int $id, array $data): mixed
    {
        $emp = $this->findEmployee($id);
        $emp->update($data);
        return $emp->fresh();
    }

    public function delete(int $id): mixed
    {
        $emp = $this->findEmployee($id);
        return $emp->delete();
    }

    // ── Contrato laboral ──────────────────────────────────────────────────────

    public function upsertLaborContract(int $employeeId, array $data): mixed
    {
        $this->findEmployee($employeeId);
        return EmployeeLaborContract::updateOrCreate(
            ['company_id' => $this->companyId(), 'employee_id' => $employeeId],
            $data
        );
    }

    // ── Afiliaciones ──────────────────────────────────────────────────────────

    public function upsertAffiliation(int $employeeId, array $data): mixed
    {
        $this->findEmployee($employeeId);
        return EmployeeAffiliation::updateOrCreate(
            ['company_id' => $this->companyId(), 'employee_id' => $employeeId],
            $data
        );
    }

    // ── Cuenta bancaria ───────────────────────────────────────────────────────

    public function upsertBankAccount(int $employeeId, array $data): mixed
    {
        $this->findEmployee($employeeId);
        return EmployeeBankAccount::updateOrCreate(
            ['company_id' => $this->companyId(), 'employee_id' => $employeeId],
            $data
        );
    }

    // ── Dotaciones ────────────────────────────────────────────────────────────

    public function getEquipment(int $employeeId): mixed
    {
        $this->findEmployee($employeeId);
        return EmployeeEquipment::where('employee_id', $employeeId)
            ->where('company_id', $this->companyId())
            ->orderByDesc('assigned_at')->get();
    }

    public function createEquipment(int $employeeId, array $data): mixed
    {
        $this->findEmployee($employeeId);
        return EmployeeEquipment::create(array_merge($data, [
            'company_id'  => $this->companyId(),
            'employee_id' => $employeeId,
        ]));
    }

    public function updateEquipment(int $employeeId, int $equipmentId, array $data): mixed
    {
        $eq = EmployeeEquipment::where('id', $equipmentId)
            ->where('employee_id', $employeeId)
            ->where('company_id', $this->companyId())
            ->firstOrFail();
        $eq->update($data);
        return $eq->fresh();
    }

    public function deleteEquipment(int $employeeId, int $equipmentId): mixed
    {
        $eq = EmployeeEquipment::where('id', $equipmentId)
            ->where('employee_id', $employeeId)
            ->where('company_id', $this->companyId())
            ->firstOrFail();
        return $eq->delete();
    }

    // ── Descargos ─────────────────────────────────────────────────────────────

    public function getDisciplinary(int $employeeId): mixed
    {
        $this->findEmployee($employeeId);
        return EmployeeDisciplinaryRecord::where('employee_id', $employeeId)
            ->where('company_id', $this->companyId())
            ->orderByDesc('incident_date')->get();
    }

    public function createDisciplinary(int $employeeId, array $data): mixed
    {
        $this->findEmployee($employeeId);
        return EmployeeDisciplinaryRecord::create(array_merge($data, [
            'company_id'  => $this->companyId(),
            'employee_id' => $employeeId,
        ]));
    }

    public function updateDisciplinary(int $employeeId, int $recordId, array $data): mixed
    {
        $rec = EmployeeDisciplinaryRecord::where('id', $recordId)
            ->where('employee_id', $employeeId)
            ->where('company_id', $this->companyId())
            ->firstOrFail();
        $rec->update($data);
        return $rec->fresh();
    }

    public function deleteDisciplinary(int $employeeId, int $recordId): mixed
    {
        $rec = EmployeeDisciplinaryRecord::where('id', $recordId)
            ->where('employee_id', $employeeId)
            ->where('company_id', $this->companyId())
            ->firstOrFail();
        return $rec->delete();
    }

    // ── Nómina ────────────────────────────────────────────────────────────────

    public function getPayrolls(int $employeeId): mixed
    {
        $this->findEmployee($employeeId);
        return EmployeePayroll::where('employee_id', $employeeId)
            ->where('company_id', $this->companyId())
            ->orderByDesc('period')->get();
    }

    public function createPayroll(int $employeeId, array $data): mixed
    {
        $this->findEmployee($employeeId);
        return EmployeePayroll::create(array_merge($data, [
            'company_id'  => $this->companyId(),
            'employee_id' => $employeeId,
        ]));
    }

    public function updatePayroll(int $employeeId, int $payrollId, array $data): mixed
    {
        $pay = EmployeePayroll::where('id', $payrollId)
            ->where('employee_id', $employeeId)
            ->where('company_id', $this->companyId())
            ->firstOrFail();
        $pay->update($data);
        return $pay->fresh();
    }

    public function deletePayroll(int $employeeId, int $payrollId): mixed
    {
        $pay = EmployeePayroll::where('id', $payrollId)
            ->where('employee_id', $employeeId)
            ->where('company_id', $this->companyId())
            ->firstOrFail();
        return $pay->delete();
    }

    // ── Ubicación en tiempo real ───────────────────────────────────────

    public function updateLocation(int $employeeId, float $latitude, float $longitude): mixed
    {
        $emp = $this->findEmployee($employeeId);
        $emp->latitude           = $latitude;
        $emp->longitude          = $longitude;
        $emp->last_location_update = now();
        $emp->save();

        \App\Events\TechnicianLocationUpdated::dispatch(
            $emp->id, $emp->first_name, $emp->last_name, $emp->job_title, $latitude, $longitude
        );

        return $emp->fresh();
    }

    public function getTechnicianLocations(array $filters = []): mixed
    {
        $minutes = (int) ($filters['last_update_minutes'] ?? 30);
        $q = Employee::where('company_id', $this->companyId())
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->where('last_location_update', '>=', now()->subMinutes($minutes))
            ->select('id', 'first_name', 'last_name', 'job_title', 'latitude', 'longitude', 'last_location_update');

        if (!empty($filters['job_title'])) {
            $q->where('job_title', $filters['job_title']);
        }

        return $q->get();
    }
}
