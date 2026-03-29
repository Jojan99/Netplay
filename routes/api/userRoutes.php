<?php

use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;


Route::prefix('user')->group(function () {
    // Lectura pública de usuario por ID (sin JWT, usada por facturación externa)
    Route::get('getUserById/{id}', [UserController::class, 'getUserById'])->withoutMiddleware('jwt.verify');
    Route::get('getUserByIdBost/{id}', [UserController::class, 'getUserByIdBost'])->withoutMiddleware('jwt.verify');

    // Gestión de clientes: admin y contador
    Route::middleware('role:admin,contador')->group(function () {
        Route::post('createUserData', [UserController::class, 'createUserData']);
        Route::put('updateUserData', [UserController::class, 'updateUserData']);
        Route::get('getUserAll', [UserController::class, 'getUserAll']);
        Route::get('getCountUser', [UserController::class, 'getCountUser']);
        Route::delete('deleteUserDataById/{id}', [UserController::class, 'DeleteUserData']);
    });

    // Estadísticas financieras: solo admin y contador
    Route::middleware('role:admin,contador')->group(function () {
        Route::get('getTotalPriceMonth', [UserController::class, 'getTotalPriceMonth']);
        Route::get('getTotalClientRegisterMonth', [UserController::class, 'getTotalClientRegisterMonth']);
        Route::get('getTrazaFacture', [UserController::class, 'getTrazaFacture']);
    });

    // PDF: admin, contador y técnico
    Route::middleware('role:admin,contador,tecnico')->group(function () {
        Route::get('generatePdf', [UserController::class, 'generatePdf']);
    });
});
