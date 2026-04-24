<?php

namespace App\Repositories\Interfaces;

interface EmployeeRepositoryInterface
{
    public function getAll(array $filters = []): mixed;
    public function getById(int $id): mixed;
    public function create(array $data): mixed;
    public function update(int $id, array $data): mixed;
    public function delete(int $id): mixed;
    public function getAvailableStaff(): mixed;

    // Sub-recursos
    public function upsertLaborContract(int $employeeId, array $data): mixed;
    public function upsertAffiliation(int $employeeId, array $data): mixed;
    public function upsertBankAccount(int $employeeId, array $data): mixed;

    public function getEquipment(int $employeeId): mixed;
    public function createEquipment(int $employeeId, array $data): mixed;
    public function updateEquipment(int $employeeId, int $equipmentId, array $data): mixed;
    public function deleteEquipment(int $employeeId, int $equipmentId): mixed;

    public function getDisciplinary(int $employeeId): mixed;
    public function createDisciplinary(int $employeeId, array $data): mixed;
    public function updateDisciplinary(int $employeeId, int $recordId, array $data): mixed;
    public function deleteDisciplinary(int $employeeId, int $recordId): mixed;

    public function getPayrolls(int $employeeId): mixed;
    public function createPayroll(int $employeeId, array $data): mixed;
    public function updatePayroll(int $employeeId, int $payrollId, array $data): mixed;
    public function deletePayroll(int $employeeId, int $payrollId): mixed;
}
