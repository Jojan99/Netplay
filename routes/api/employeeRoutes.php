<?php

use App\Http\Controllers\EmployeeController;
use Illuminate\Support\Facades\Route;

Route::prefix('employees')->middleware(['jwt.verify'])->group(function () {

    // Empleados CRUD
    Route::get('/',              [EmployeeController::class, 'index']);
    Route::get('/available-staff', [EmployeeController::class, 'availableStaff']);
    Route::get('/{id}',          [EmployeeController::class, 'show']);
    Route::post('/',   [EmployeeController::class, 'store']);
    Route::put('/{id}', [EmployeeController::class, 'update']);
    Route::delete('/{id}', [EmployeeController::class, 'destroy']);

    // Contrato laboral
    Route::put('/{id}/labor-contract', [EmployeeController::class, 'upsertLaborContract']);

    // Afiliaciones
    Route::put('/{id}/affiliation', [EmployeeController::class, 'upsertAffiliation']);

    // Cuenta bancaria
    Route::put('/{id}/bank-account', [EmployeeController::class, 'upsertBankAccount']);

    // Dotaciones
    Route::get('/{id}/equipment',           [EmployeeController::class, 'getEquipment']);
    Route::post('/{id}/equipment',          [EmployeeController::class, 'createEquipment']);
    Route::put('/{id}/equipment/{eqId}',    [EmployeeController::class, 'updateEquipment']);
    Route::delete('/{id}/equipment/{eqId}', [EmployeeController::class, 'deleteEquipment']);

    // Descargos
    Route::get('/{id}/disciplinary',            [EmployeeController::class, 'getDisciplinary']);
    Route::post('/{id}/disciplinary',           [EmployeeController::class, 'createDisciplinary']);
    Route::put('/{id}/disciplinary/{recId}',    [EmployeeController::class, 'updateDisciplinary']);
    Route::delete('/{id}/disciplinary/{recId}', [EmployeeController::class, 'deleteDisciplinary']);

    // Nómina
    Route::get('/{id}/payroll',            [EmployeeController::class, 'getPayrolls']);
    Route::post('/{id}/payroll',           [EmployeeController::class, 'createPayroll']);
    Route::put('/{id}/payroll/{payId}',    [EmployeeController::class, 'updatePayroll']);
    Route::delete('/{id}/payroll/{payId}', [EmployeeController::class, 'deletePayroll']);
});
