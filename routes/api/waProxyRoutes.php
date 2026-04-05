<?php

use App\Http\Controllers\WaProxyController;
use Illuminate\Support\Facades\Route;

/**
 * Proxy HTTPS → HTTP al whatsapp-service.
 * Resuelve Mixed Content cuando el frontend está en HTTPS.
 * Requiere JWT válido (middleware api + jwt.verify del RouteServiceProvider).
 */
Route::any('wa-proxy/{path}', [WaProxyController::class, 'proxy'])
    ->where('path', '.*');
