<?php

use App\Http\Controllers\TicketController;
use Illuminate\Support\Facades\Route;


Route::prefix('ticket')->group(function () {

    Route::middleware('role:admin,tecnico,contador')->group(function () {
        Route::get('getTypeServiceAll',  [TicketController::class, 'getTypeServiceAll']);
        Route::get('getTypePriorityAll', [TicketController::class, 'getTypePriorityAll']);
        Route::get('getTechnicaAll',     [TicketController::class, 'getTechnicaAll']);
        Route::get('stats',              [TicketController::class, 'getStats']);
        Route::get('all',                [TicketController::class, 'getAllTickets']);
        Route::get('updates',            [TicketController::class, 'getTicketsSince']);
        Route::get('{id}/notes',         [TicketController::class, 'getNotes']);
        Route::get('getByUser/{user_id}',[TicketController::class, 'getTicketsByUser']);
        Route::get('{id}',               [TicketController::class, 'getTicketById']);
    });

    Route::middleware('role:admin,tecnico')->group(function () {
        Route::post('createTicket',           [TicketController::class, 'createTicket']);
        Route::post('getTicketInProgressAll', [TicketController::class, 'getTicketInProgressAll']);
        Route::post('updateTicket',           [TicketController::class, 'updateTicket']);
        Route::post('{id}/notes',             [TicketController::class, 'addNote']);
        Route::post('{id}/close',             [TicketController::class, 'closeTicket']);
        Route::post('{id}/reopen',            [TicketController::class, 'reopenTicket']);
        Route::post('{id}/reassign',          [TicketController::class, 'reassignTicket']);
    });

    Route::middleware('role:admin')->group(function () {
        Route::delete('{id}', [TicketController::class, 'deleteTicket']);
    });
});
