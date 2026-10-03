<?php

use App\Http\Controllers\DiagnosticoDePantallaController;
use Illuminate\Support\Facades\Route;

// TEMPORAL: medidas de las ventanas en el teléfono (ver el controlador).
Route::post('diagnostico/pantalla', [DiagnosticoDePantallaController::class, 'guardar'])->middleware('throttle:40,1');
