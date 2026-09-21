<?php

use App\Http\Controllers\CarteraController;
use Illuminate\Support\Facades\Route;

// Tablero de cartera y cobranza (módulo "finanzas").
Route::prefix('cartera')->middleware('role:admin,contador')->group(function () {
    Route::get('resumen', [CarteraController::class, 'resumen']);
    Route::get('deudores', [CarteraController::class, 'deudores']);
    Route::get('mora', [CarteraController::class, 'mora']);
});
