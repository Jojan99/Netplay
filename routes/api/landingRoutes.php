<?php

use App\Http\Controllers\Landing\LandingPublicController;
use App\Http\Controllers\Landing\LandingAdminController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas Landing Page
|--------------------------------------------------------------------------
|
| Pública (sin JWT):
|   GET  /api/landing/{slug}            → datos completos de la landing
|
| Admin (jwt.verify + role:admin):
|   GET  /api/landing-admin/config      → leer config
|   POST /api/landing-admin/config      → guardar config
|   GET  /api/landing-admin/sections    → listar secciones
|   POST /api/landing-admin/sections    → crear sección
|   PUT  /api/landing-admin/sections/{id}     → editar sección
|   DELETE /api/landing-admin/sections/{id}   → eliminar sección
|   PUT  /api/landing-admin/sections/reorder  → reordenar
|   GET  /api/landing-admin/nav         → listar menú
|   POST /api/landing-admin/nav         → crear ítem menú
|   PUT  /api/landing-admin/nav/{id}    → editar ítem menú
|   DELETE /api/landing-admin/nav/{id}  → eliminar ítem menú
*/

// Pública
Route::get('landing/{slug}', [LandingPublicController::class, 'show']);

// Admin (protegidas por jwt.verify aplicado globalmente en RouteServiceProvider)
Route::prefix('landing-admin')->middleware(['jwt.verify', 'role:admin'])->group(function () {
    Route::get('config',  [LandingAdminController::class, 'getConfig']);
    Route::post('config', [LandingAdminController::class, 'saveConfig']);

    Route::get('sections',            [LandingAdminController::class, 'getSections']);
    Route::post('sections',           [LandingAdminController::class, 'createSection']);
    Route::put('sections/reorder',    [LandingAdminController::class, 'reorderSections']);
    Route::put('sections/{id}',       [LandingAdminController::class, 'updateSection']);
    Route::delete('sections/{id}',    [LandingAdminController::class, 'deleteSection']);

    Route::get('nav',         [LandingAdminController::class, 'getNav']);
    Route::post('nav',        [LandingAdminController::class, 'createNav']);
    Route::put('nav/{id}',    [LandingAdminController::class, 'updateNav']);
    Route::delete('nav/{id}', [LandingAdminController::class, 'deleteNav']);
});
