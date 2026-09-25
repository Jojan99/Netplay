<?php

use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;


Route::prefix('user')->group(function () {
    // Rutas públicas (sin JWT) usadas por portal de clientes
    Route::get('getUserById/{id}', [UserController::class, 'getUserById']);
    Route::get('getUserByIdBost/{id}', [UserController::class, 'getUserByIdBost']);

    // Solo admin: crear, modificar y eliminar clientes
    Route::middleware('role:admin')->group(function () {
        Route::post('createUserData', [UserController::class, 'createUserData']);
        Route::put('updateUserData', [UserController::class, 'updateUserData']);
        Route::delete('deleteUserDataById/{id}', [UserController::class, 'DeleteUserData']);
        // Clientes eliminados: listarlos y volver a darlos de alta. Va antes de
        // '{id}/en-router' para que 'eliminados' no se lea como un id.
        Route::get('eliminados',       [UserController::class, 'eliminados']);
        Route::post('{id}/reinstalar', [UserController::class, 'reinstalar'])->whereNumber('id');
        Route::get('{id}/reinstalar/ip', [UserController::class, 'reinstalarRevisarIp'])->whereNumber('id');
        // Qué tiene el cliente en el MikroTik, antes de eliminarlo.
        Route::get('{id}/en-router', [UserController::class, 'enRouter'])->whereNumber('id');
        // Facturación electrónica del cliente (cobra por la pasarela).
        Route::post('{id}/facturacion-electronica', [UserController::class, 'facturacionElectronica'])->whereNumber('id');
        // Trato especial: el descuento que se le aplica todos los meses.
        Route::post('{id}/descuento', [UserController::class, 'guardarDescuento'])->whereNumber('id');
    });

    // Admin y contador: consultar clientes y generar PDF
    Route::middleware('role:admin,contador')->group(function () {
        Route::get('getUserAll', [UserController::class, 'getUserAll']);
        Route::get('lista', [UserController::class, 'lista']);
        Route::get('search', [UserController::class, 'search']);
        Route::get('getCountUser', [UserController::class, 'getCountUser']);
        Route::get('generatePdf', [UserController::class, 'generatePdf']);
        Route::get('getTotalPriceMonth', [UserController::class, 'getTotalPriceMonth']);
        Route::get('getTotalClientRegisterMonth', [UserController::class, 'getTotalClientRegisterMonth']);
        Route::get('getTrazaFacture', [UserController::class, 'getTrazaFacture']);
        Route::get('auditLog/{user_id}', [UserController::class, 'getAuditLog']);
        Route::get('exportUsers', [UserController::class, 'exportUsers']);
    });
});

Route::get('clients/{userId}/statement', [\App\Http\Controllers\ClientStatementController::class, 'show'])->middleware('module:usuario,finanzas');
