<?php

use App\Http\Controllers\ImportadorController;
use Illuminate\Support\Facades\Route;

/**
 * Importar clientes desde WispHub / Mikrowisp (API o archivo exportado).
 * Sólo el administrador de la empresa: crea clientes en bloque.
 */
Route::prefix('importador')->middleware('role:admin')->group(function () {
    Route::get('/',                             [ImportadorController::class, 'index']);
    Route::post('api',                          [ImportadorController::class, 'desdeApi'])->middleware('throttle:10,1');
    Route::post('archivo',                      [ImportadorController::class, 'desdeArchivo'])->middleware('throttle:20,1');
    Route::delete('credenciales/{origen}',      [ImportadorController::class, 'olvidarCredencial'])->whereIn('origen', ['wisphub', 'mikrowisp']);
    // Grupos de facturación, para crearlos sin salir del importador.
    Route::post('grupos',                       [ImportadorController::class, 'crearGrupo']);
    // Normalizar los comentarios del MikroTik: primero muestra, después escribe.
    Route::post('comentarios',                  [ImportadorController::class, 'comentarios']);
    Route::get('{id}',                          [ImportadorController::class, 'ver'])->whereNumber('id');
    Route::get('{id}/filas',                    [ImportadorController::class, 'filas'])->whereNumber('id');
    Route::post('{id}/mapeo',                   [ImportadorController::class, 'mapeo'])->whereNumber('id');
    Route::post('{id}/ejecutar',                [ImportadorController::class, 'ejecutar'])->whereNumber('id');
    Route::post('{id}/cancelar',                [ImportadorController::class, 'cancelar'])->whereNumber('id');
    Route::post('{id}/nombres',                 [ImportadorController::class, 'nombres'])->whereNumber('id');
    Route::post('{id}/asignar',                 [ImportadorController::class, 'asignar'])->whereNumber('id');
    // Cotejo con el MikroTik: sólo lee el router.
    Route::post('{id}/cotejo',                  [ImportadorController::class, 'cotejo'])->whereNumber('id');
    Route::get('{id}/reporte',                  [ImportadorController::class, 'reporte'])->whereNumber('id');
});
