<?php

use App\Http\Controllers\TicketController;
use Illuminate\Support\Facades\Route;


Route::prefix('ticket')->group(function () {
    // Catálogos: accesibles por admin, técnico y contador
    Route::middleware('role:admin,tecnico,contador')->group(function () {
        Route::get('getTypeServiceAll', [TicketController::class, 'getTypeServiceAll']);
        Route::get('getTypePriorityAll', [TicketController::class, 'getTypePriorityAll']);
        Route::get('getTechnicaAll', [TicketController::class, 'getTechnicaAll']);
    });

    // Gestión de tickets: solo admin y técnico
    Route::middleware('role:admin,tecnico')->group(function () {
        Route::post('createTicket', [TicketController::class, 'createTicket']);
        Route::post('getTicketInProgressAll', [TicketController::class, 'getTicketInProgressAll']);
        Route::post('updateTicket', [TicketController::class, 'updateTicket']);
    });
});
