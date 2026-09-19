<?php

use App\Http\Controllers\PlataformaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| La plataforma y el subdominio de cada empresa
|--------------------------------------------------------------------------
|   GET  /api/plataforma/sitio                  → ¿plataforma o empresa? nombre y logo
|   GET  /api/plataforma/planes                 → planes de la página pública
|   GET  /api/plataforma/logo/{slug}            → logo de la empresa (imagen)
|   GET  /api/plataforma/subdominio/disponible  → ?s=netplay o ?nombre=Netplay SAS
|   GET  /api/plataforma/codigo                 → ¿sirve este cupón o código de referido?
|   GET  /api/plataforma/mi-referido            → el código de referido de la empresa y su crédito
|   GET  /api/plataforma/mi-subdominio          → admin: el de su empresa
|   PUT  /api/plataforma/mi-subdominio          → admin: cambiarlo
*/
Route::prefix('plataforma')->group(function () {

    Route::get('sitio', [PlataformaController::class, 'sitio'])
        ->withoutMiddleware('jwt.verify');

    Route::get('planes', [PlataformaController::class, 'planes'])
        ->withoutMiddleware('jwt.verify');

    Route::get('logo/{slug}', [PlataformaController::class, 'logo'])
        ->withoutMiddleware('jwt.verify')
        ->where('slug', '[a-z0-9-]+');

    Route::get('subdominio/disponible', [PlataformaController::class, 'disponible'])
        ->withoutMiddleware('jwt.verify')
        ->middleware('throttle:40,1');

    // Cupón o código de referido, para el formulario de alta (sin sesión).
    Route::get('codigo', [PlataformaController::class, 'codigo'])
        ->withoutMiddleware('jwt.verify')
        ->middleware('throttle:30,1');

    // El código de referido de la propia empresa y su crédito.
    Route::get('mi-referido', [PlataformaController::class, 'miReferido']);

    Route::middleware('role:admin')->group(function () {
        Route::get('mi-subdominio', [PlataformaController::class, 'miSubdominio']);
        Route::put('mi-subdominio', [PlataformaController::class, 'cambiarSubdominio']);
    });
});
