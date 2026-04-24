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

// Página de firma de contrato para el cliente (acceso por token, sin login)
Route::get('/contrato/firmar/{token}', [\App\Http\Controllers\ContractSignController::class, 'show'])
    ->name('contract.sign');
