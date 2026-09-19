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
    Route::get('tablero',                   [EgresosController::class, 'tablero']);
    Route::get('export',                    [EgresosController::class, 'exportEgresosCSV']);
    Route::post('create-v2',                [EgresosController::class, 'createEgresoV2']);

    // Categorías por empresa (antes de {id} para que no se las trague la ruta comodín)
    Route::get('categorias',                [EgresosController::class, 'listarCategorias']);
    Route::post('categorias',               [EgresosController::class, 'crearCategoria']);
    Route::put('categorias/{id}',           [EgresosController::class, 'actualizarCategoria']);
    Route::patch('categorias/{id}/toggle',  [EgresosController::class, 'alternarCategoria']);
    Route::delete('categorias/{id}',        [EgresosController::class, 'eliminarCategoria']);

    Route::get('{id}/comprobante',          [EgresosController::class, 'verComprobante'])->whereNumber('id');
    Route::post('{id}/repetir',             [EgresosController::class, 'repetirEgreso'])->whereNumber('id');
    Route::put('{id}',                      [EgresosController::class, 'updateEgreso'])->whereNumber('id');
    Route::delete('{id}',                   [EgresosController::class, 'deleteEgreso'])->whereNumber('id');
});
