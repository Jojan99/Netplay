<?php

use App\Http\Controllers\AtajosController;
use Illuminate\Support\Facades\Route;

// Buscador (Ctrl+K) y ventanas rápidas del panel.
Route::prefix('atajos')->middleware('role:admin,tecnico,contador')->group(function () {
    Route::get('buscar', [AtajosController::class, 'buscar']);
    Route::get('cliente/{userId}', [AtajosController::class, 'cliente'])->whereNumber('userId');
});
