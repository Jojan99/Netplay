<?php

use App\Http\Controllers\SuspensionMasivaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Suspensión masiva por grupo de corte
|--------------------------------------------------------------------------
| Cortarle el servicio a decenas de clientes de una vez es decisión del administrador.
*/
Route::prefix('suspension-masiva')->middleware('role:admin')->group(function () {
    Route::get('opciones',                [SuspensionMasivaController::class, 'opciones']);
    Route::get('candidatos',              [SuspensionMasivaController::class, 'candidatos']);
    Route::post('lotes',                  [SuspensionMasivaController::class, 'crear'])->middleware('throttle:6,1');
    Route::get('lotes/{id}',              [SuspensionMasivaController::class, 'ver'])->whereNumber('id');
    Route::post('lotes/{id}/cancelar',    [SuspensionMasivaController::class, 'cancelar'])->whereNumber('id');
});
