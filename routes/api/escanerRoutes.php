<?php

use App\Http\Controllers\EscanerCelularController;
use Illuminate\Support\Facades\Route;

// El celular como lector del computador. Las de «sesiones» las usa el computador con su sesión;
// las de «publico» el celular, sólo con el token del QR (sin JWT, con tope de pedidos).
Route::prefix('escaner')->group(function () {
    Route::middleware('jwt.verify')->group(function () {
        Route::post('sesiones', [EscanerCelularController::class, 'abrir']);
        Route::get('sesiones/{token}/lecturas', [EscanerCelularController::class, 'lecturas']);
        Route::post('sesiones/{token}/veredicto', [EscanerCelularController::class, 'veredicto']);
        Route::delete('sesiones/{token}', [EscanerCelularController::class, 'cerrar']);
    });

    Route::middleware('throttle:240,1')->group(function () {
        Route::get('publico/{token}', [EscanerCelularController::class, 'datos']);
        Route::post('publico/{token}/lecturas', [EscanerCelularController::class, 'leer']);
        Route::get('publico/{token}/veredictos', [EscanerCelularController::class, 'veredictos']);
    });
});
