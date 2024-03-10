<?php

use App\Http\Controllers\DniController;
use Illuminate\Support\Facades\Route;


Route::prefix('dni')->group(function () {
    Route::get('getDniAll', [DniController::class, 'getDniAll'])->withoutMiddleware('jwt.verify');
    Route::get('pruebaMikro', [DniController::class, 'pruebaMikro'])->withoutMiddleware('jwt.verify');
    Route::get('pruebaMikroPing', [DniController::class, 'pruebaMikroPing'])->withoutMiddleware('jwt.verify');
    Route::get('pruebaMikroAll', [DniController::class, 'pruebaMikroAll'])->withoutMiddleware('jwt.verify');
});