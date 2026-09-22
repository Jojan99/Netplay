<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

// Páginas legales públicas. Meta las exige para aprobar la app, y la Ley 1581
// obliga a tener la política de tratamiento de datos a la vista.
Route::get('/politica-de-privacidad', [\App\Http\Controllers\PaginasLegalesController::class, 'privacidad'])
    ->name('legal.privacidad');
Route::get('/eliminacion-de-datos', [\App\Http\Controllers\PaginasLegalesController::class, 'eliminacionDeDatos'])
    ->name('legal.eliminacion');

// Página de firma de contrato para el cliente (acceso por token, sin login)
Route::get('/contrato/firmar/{token}', [\App\Http\Controllers\ContractSignController::class, 'show'])
    ->name('contract.sign');
