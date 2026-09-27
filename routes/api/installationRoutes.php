<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\InstallationOrderController;

Route::prefix('installations')->middleware('module:installations')->group(function () {
    // Lo que un técnico también hace: ver la agenda, tomar un pedido en la calle, avanzar el estado
    // de la suya y dejarla instalada. Nada de precios ni de mover técnicos de otro.
    Route::get('/', [InstallationOrderController::class, 'index']);
    Route::post('/', [InstallationOrderController::class, 'store']);
    // Una orden de práctica para recorrer el flujo sin tocar la red. Va antes de '/{id}'.
    Route::post('/practica', [InstallationOrderController::class, 'practica']);
    Route::get('/plans', [InstallationOrderController::class, 'plans']);
    Route::get('/payment-methods', [InstallationOrderController::class, 'paymentMethods']);
    Route::get('/technicians', [InstallationOrderController::class, 'availableTechnicians']);
    // Va antes de '/{id}': si no, «por-cedula» se lee como un id.
    Route::get('/por-cedula/{dni}', [InstallationOrderController::class, 'porCedula']);
    Route::get('/{id}', [InstallationOrderController::class, 'show']);

    Route::post('/{id}/confirm', [InstallationOrderController::class, 'confirm']);
    Route::post('/{id}/start', [InstallationOrderController::class, 'start']);
    Route::post('/{id}/complete', [InstallationOrderController::class, 'complete']);
    // Lo que hace el técnico en la calle: elegir el equipo y dejar todo listo.
    Route::get('/{id}/equipos',      [InstallationOrderController::class, 'equiposDisponibles']);
    Route::post('/{id}/provisionar', [InstallationOrderController::class, 'provisionar']);

    Route::get('/{id}/logs', [InstallationOrderController::class, 'logs']);
    Route::post('/{id}/logs', [InstallationOrderController::class, 'createLog']);

    // Borrar queda abierto: adentro sólo deja borrar una orden pendiente, y si es de un técnico sólo
    // la suya y de práctica (ver InstallationOrderController::destroy). El resto sigue siendo de oficina.
    Route::delete('/{id}', [InstallationOrderController::class, 'destroy']);

    // ── De acá para abajo, sólo oficina (admin o contador) ────────────────────
    // Precio, comisión, a quién se le asigna, cancelar o editar la orden: nada
    // de esto lo decide quien está parado en la puerta del cliente.
    Route::middleware('role:admin,contador')->group(function () {
        Route::get('/dashboard', [InstallationOrderController::class, 'dashboard']);
        Route::put('/{id}', [InstallationOrderController::class, 'update']);
        Route::post('/{id}/cancel', [InstallationOrderController::class, 'cancel']);
        Route::put('/{id}/payment', [InstallationOrderController::class, 'updatePayment']);
        Route::put('/{id}/technicians', [InstallationOrderController::class, 'assignTechnicians']);
        Route::get('/{id}/commission', [InstallationOrderController::class, 'calculateCommission']);
    });
});