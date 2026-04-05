<?php

use App\Http\Controllers\EgresosController;
use Illuminate\Support\Facades\Route;

Route::prefix('egresos')->middleware('role:admin,contador')->group(function () {
    Route::post('createEgresos',            [EgresosController::class, 'createEgresos']);
    Route::get('getEgresosAll',             [EgresosController::class, 'getEgresosAll']);
    Route::get('getPriceEgresseAll',        [EgresosController::class, 'getPriceEgresseAll']);
    // ?from=YYYY-MM-DD&to=YYYY-MM-DD
    Route::get('resumen',                   [EgresosController::class, 'getPriceEgresseByRange']);
    Route::get('ingresos',                  [EgresosController::class, 'getIngresosDetailed']);

    // Egresos v2
    Route::get('list',                      [EgresosController::class, 'listPaginated']);
    Route::post('create-v2',                [EgresosController::class, 'createEgresoV2']);
    Route::put('{id}',                      [EgresosController::class, 'updateEgreso']);
    Route::delete('{id}',                   [EgresosController::class, 'deleteEgreso']);
    Route::get('export',                    [EgresosController::class, 'exportEgresosCSV']);
});
