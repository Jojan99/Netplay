<?php

use App\Http\Controllers\WaProxyController;
use Illuminate\Support\Facades\Route;

/**
 * Proxy HTTPS → HTTP al whatsapp-service.
 * Resuelve Mixed Content cuando el frontend está en HTTPS.
 * Requiere JWT válido (middleware api + jwt.verify del RouteServiceProvider).
 */
/*
 * La ruta aceptaba cualquier {path} y solo pedía JWT: cualquier usuario
 * autenticado, con el perfil más bajo, podía alcanzar cualquier endpoint del
 * servicio Node llevando la api-key de su empresa, incluido /admin y el borrado
 * de instancias. Ahora se limita a los prefijos que usa el panel y se exige
 * tener el módulo de CRM o de WhatsApp (el perfil ADMIN pasa siempre).
 */
Route::any('wa-proxy/{path}', [WaProxyController::class, 'proxy'])
    ->where('path', '^(crm|instances)(/.*)?$')
    ->middleware('module:crm,whatsapp');
