<?php

use App\Http\Controllers\Crm\ConversationController;
use App\Http\Controllers\ManagementRouterController;
use App\Http\Controllers\SnmpController;

use Illuminate\Support\Facades\Route;


Route::prefix('management')->group(function () {
    Route::post('UpdateStatus', [ManagementRouterController::class, 'UpdateStatus']);
    Route::post('disableUser', [ManagementRouterController::class, 'disableUser']);
    Route::get('getCpuStatus', [ManagementRouterController::class, 'getCpuStatus'])->withoutMiddleware('jwt.verify');
    Route::get('getCpuStatus1', [ManagementRouterController::class, 'getCpuStatus1'])->withoutMiddleware('jwt.verify');
    Route::get('getOntPort', [ManagementRouterController::class, 'getOntPort'])->withoutMiddleware('jwt.verify');
    Route::get('getOntStatusAll', [ManagementRouterController::class, 'getOntStatusAll'])->withoutMiddleware('jwt.verify');
    Route::post('registerOnt', [ManagementRouterController::class, 'registerOnt'])->withoutMiddleware('jwt.verify');
    Route::post('deleteontOnt', [ManagementRouterController::class, 'deleteontOnt'])->withoutMiddleware('jwt.verify');
    Route::get('getCpuStatusSnmpnew', [SnmpController::class, 'getCpuStatusSnmpnew'])->withoutMiddleware('jwt.verify');
    Route::get('getCpuStatusSnmp', [ManagementRouterController::class, 'getCpuStatusSnmp'])->withoutMiddleware('jwt.verify');
    Route::get('getOntAutoFind', [SnmpController::class, 'getOntAutoFind'])->withoutMiddleware('jwt.verify');
    Route::get('prueba', [SnmpController::class, 'prueba'])->withoutMiddleware('jwt.verify');
    Route::get('getOntInfo/{id}', [ManagementRouterController::class, 'getOntInfo'])->withoutMiddleware('jwt.verify');
    Route::post('getIpAvalibles', [ManagementRouterController::class, 'getIpAvalibles'])->withoutMiddleware('jwt.verify');
    Route::get('getLanSegments', [ManagementRouterController::class, 'getLanSegments'])->withoutMiddleware('jwt.verify');
    Route::post('getIpAvalibles', [ManagementRouterController::class, 'getIpAvalibles'])->withoutMiddleware('jwt.verify');
    Route::post('autorizarServicio', [ManagementRouterController::class, 'autorizarServicio'])->withoutMiddleware('jwt.verify');
    Route::post('autorizarServicio', [ManagementRouterController::class, 'autorizarServicio'])->withoutMiddleware('jwt.verify');
  // 📥 Inbox
    Route::get(
        'inbox',
        [ConversationController::class, 'inbox']
    );

    // 📥 Obtener mensajes
    Route::get(
        'conversations/{conversationId}/messages',
        [ConversationController::class, 'getMessages']
    )->withoutMiddleware('jwt.verify');

    // 📤 Enviar mensaje (ESTA ES LA CLAVE)
    Route::post(
        'conversations/{conversationId}/messages',
        [ConversationController::class, 'store']
    );

    // 📥 Webhook WhatsApp
    Route::post(
        'receiveMessage',
        [ConversationController::class, 'receiveMessage']
    )->withoutMiddleware('jwt.verify');

    // 🔁 Transferir conversación
    Route::post(
        'conversations/{conversationId}/transfer',
        [ConversationController::class, 'transfer']
    )->withoutMiddleware('jwt.verify');

    // 🔒 Cerrar conversación
     Route::post(
    'conversations/{conversationId}/close',
    [ConversationController::class, 'close']
    )->withoutMiddleware('jwt.verify');

    Route::post(
    'conversations/{conversationId}/transfer',
    [ConversationController::class, 'transfer']
);

Route::get('agents', [ConversationController::class, 'agents'])->withoutMiddleware('jwt.verify');

// 📤 Enviar MEDIA (imagen / video / audio / documento)
Route::post(
    'conversations/{conversationId}/media',
    [ConversationController::class, 'sendMedia']
);


    // Route::get('/management/getOntInfo/{id}', [ManagementRouterController::class, 'getOntInfo']);
    
    
});