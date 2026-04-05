<?php

use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;


Route::prefix('user')->group(function () {
    // Rutas públicas (sin JWT) usadas por portal de clientes
    Route::get('getUserById/{id}', [UserController::class, 'getUserById'])->withoutMiddleware('jwt.verify');
    Route::get('getUserByIdBost/{id}', [UserController::class, 'getUserByIdBost'])->withoutMiddleware('jwt.verify');

    // Solo admin: crear, modificar y eliminar clientes
    Route::middleware('role:admin')->group(function () {
        Route::post('createUserData', [UserController::class, 'createUserData']);
        Route::put('updateUserData', [UserController::class, 'updateUserData']);
        Route::delete('deleteUserDataById/{id}', [UserController::class, 'DeleteUserData']);
    });

    // Admin y contador: consultar clientes y generar PDF
    Route::middleware('role:admin,contador')->group(function () {
        Route::get('getUserAll', [UserController::class, 'getUserAll']);
        Route::get('getCountUser', [UserController::class, 'getCountUser']);
        Route::get('generatePdf', [UserController::class, 'generatePdf']);
        Route::get('getTotalPriceMonth', [UserController::class, 'getTotalPriceMonth']);
        Route::get('getTotalClientRegisterMonth', [UserController::class, 'getTotalClientRegisterMonth']);
        Route::get('getTrazaFacture', [UserController::class, 'getTrazaFacture']);
        Route::get('auditLog/{user_id}', [UserController::class, 'getAuditLog']);
        Route::get('exportUsers', [UserController::class, 'exportUsers']);
    });
});
