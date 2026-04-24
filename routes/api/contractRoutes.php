<?php

use App\Http\Controllers\ContractController;
use App\Http\Controllers\ContractSignController;
use Illuminate\Support\Facades\Route;

// Firma por token — sin JWT (el cliente accede desde el link)
Route::post('/contracts/sign-token/{token}', [ContractSignController::class, 'sign']);

Route::prefix('contracts')->middleware(['jwt.verify'])->group(function () {

    // Plantillas de contrato (ADMIN / CONTADOR)
    Route::get('/',              [ContractController::class, 'index']);
    Route::get('/{id}',          [ContractController::class, 'show']);
    Route::post('/',             [ContractController::class, 'store']);
    Route::put('/{id}',          [ContractController::class, 'update']);
    Route::delete('/{id}',       [ContractController::class, 'destroy']);

    // Asignación y gestión de contratos por cliente
    Route::post('/assign',                              [ContractController::class, 'assign']);
    Route::get('/client/{userId}',                      [ContractController::class, 'clientContracts']);
    Route::get('/client-contract/{clientContractId}',   [ContractController::class, 'clientContractById']);
    Route::delete('/client-contract/{clientContractId}',[ContractController::class, 'deleteClientContract']);
    Route::post('/sign/{clientContractId}',             [ContractController::class, 'sign']);
    Route::get('/pdf/{clientContractId}',               [ContractController::class, 'pdf']);

    // Envío de link
    Route::post('/send-email/{clientContractId}',       [ContractController::class, 'sendEmail']);
    Route::post('/send-whatsapp/{clientContractId}',    [ContractController::class, 'sendWhatsApp']);
});
