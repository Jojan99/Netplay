<?php

use App\Http\Controllers\CorreoController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Correo (Mailjet) de la empresa
|--------------------------------------------------------------------------
|
| Solo el administrador de la empresa en sesión:
|   GET    /api/correo/configuracion
|   PUT    /api/correo/configuracion
|   POST   /api/correo/probar
|   DELETE /api/correo/configuracion
|
| Sin configuración propia los correos salen de la cuenta de la plataforma
| (no-reply@netvula.com) con el nombre de la empresa.
*/

Route::prefix('correo')->middleware(['jwt.verify', 'role:admin'])->group(function () {
    Route::get('configuracion',    [CorreoController::class, 'configuracion']);
    Route::put('configuracion',    [CorreoController::class, 'guardar']);
    Route::post('probar',          [CorreoController::class, 'probar']);
    Route::delete('configuracion', [CorreoController::class, 'desconectar']);
});
