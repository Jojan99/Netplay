<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\EgresosController;


Route::prefix('egresos')->middleware('role:admin,contador')->group(function () {
    Route::post('createEgresos', [EgresosController::class, 'createEgresos']);
    Route::get('getEgresosAll', [EgresosController::class, 'getEgresosAll']);
    Route::get('getPriceEgresseAll', [EgresosController::class, 'getPriceEgresseAll']);
});
