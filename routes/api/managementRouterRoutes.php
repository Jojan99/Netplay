<?php

use App\Http\Controllers\Crm\ConversationController;
use App\Http\Controllers\ManagementRouterController;
use App\Http\Controllers\OltAdminController;
use App\Http\Controllers\SnmpController;

use Illuminate\Support\Facades\Route;


Route::prefix('management')->group(function () {
    // ── Multi-Mikrotik router CRUD ──────────────────────────────────────────
    Route::get('routers',            [ManagementRouterController::class, 'listRouters']);
    Route::post('routers',           [ManagementRouterController::class, 'storeRouter']);
    Route::put('routers/{id}',       [ManagementRouterController::class, 'updateRouterById']);
    Route::delete('routers/{id}',    [ManagementRouterController::class, 'destroyRouter']);

    // ── Mikrotik info & management (aceptan ?router_id=N) ──────────────────
    Route::get('router-config',      [ManagementRouterController::class, 'getRouterConfig']);
    Route::post('router-config',     [ManagementRouterController::class, 'saveRouterConfig']);
    Route::get('router-info',        [ManagementRouterController::class, 'getRouterInfo']);
    Route::get('clients',            [ManagementRouterController::class, 'getConnectedClients']);
    Route::get('queues',             [ManagementRouterController::class, 'getQueues']);
    Route::post('queues',            [ManagementRouterController::class, 'createQueue']);
    Route::put('queues/{id}',        [ManagementRouterController::class, 'updateQueue']);
    Route::delete('queues/{id}',     [ManagementRouterController::class, 'deleteQueue']);
    Route::post('suspend-bulk',      [ManagementRouterController::class, 'suspendBulk']);

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
    Route::post('migrarIp', [ManagementRouterController::class, 'migrarIp'])->withoutMiddleware('jwt.verify');

    // ── OLT Admin ──────────────────────────────────────────────────────────
    Route::prefix('olt')->group(function () {
        Route::get('/',                    [OltAdminController::class, 'index']);
        Route::post('/',                   [OltAdminController::class, 'store']);
        Route::put('/{id}',                [OltAdminController::class, 'update']);
        Route::delete('/{id}',             [OltAdminController::class, 'destroy']);
        Route::get('/{oltId}/unauth',      [OltAdminController::class, 'unauthONTs']);
        Route::post('/{oltId}/register',   [OltAdminController::class, 'registerONT']);
        Route::delete('/{oltId}/ont',      [OltAdminController::class, 'deleteONT']);
        Route::post('/{oltId}/assign',      [OltAdminController::class, 'assignONT']);
        Route::post('/{oltId}/auto-assign', [OltAdminController::class, 'autoAssignONT']);

        // Read operations
        Route::get('/snmp-onts',              [ManagementRouterController::class, 'obtenerInformacionSNMP'])->withoutMiddleware('jwt.verify');
        Route::get('/{oltId}/onts',           [OltAdminController::class, 'authorizedONTs']);
        Route::get('/{oltId}/ont/info',       [OltAdminController::class, 'ontInfo']);        // ?fsp=0/1/0&ont_id=0
        Route::get('/{oltId}/service-ports',  [OltAdminController::class, 'servicePorts']);  // ?fsp=&ont_id=
        Route::get('/{oltId}/profiles',       [OltAdminController::class, 'getProfiles']);

        // Write operations
        Route::post('/{oltId}/ont/transfer',  [OltAdminController::class, 'transferONT']);
        Route::post('/{oltId}/ont/deactivate',[OltAdminController::class, 'deactivateONT']);
        Route::post('/{oltId}/ont/activate',  [OltAdminController::class, 'activateONT']);
        Route::post('/{oltId}/profiles/sync', [OltAdminController::class, 'syncProfiles']);
        Route::post('/{oltId}/cli',                  [OltAdminController::class, 'cliCommand']);
        Route::post('/{oltId}/ont/assign-client',    [OltAdminController::class, 'assignClientToOnt']);
        Route::get('/ont/by-user/{userId}',          [OltAdminController::class, 'getOntByUser']);
    });

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

// ── NOTAS INTERNAS ────────────────────────────────────────────────────────────
Route::get('conversations/{conversationId}/notes', [ConversationController::class, 'getNotes']);
Route::post('conversations/{conversationId}/notes', [ConversationController::class, 'addNote']);
Route::delete('notes/{noteId}', [ConversationController::class, 'deleteNote']);

// ── ETIQUETAS ─────────────────────────────────────────────────────────────────
Route::get('labels', [ConversationController::class, 'getLabels']);
Route::post('labels', [ConversationController::class, 'createLabel']);
Route::delete('labels/{labelId}', [ConversationController::class, 'deleteLabel']);
Route::get('conversations/{conversationId}/labels', [ConversationController::class, 'getConversationLabels']);
Route::post('conversations/{conversationId}/labels', [ConversationController::class, 'addConversationLabel']);
Route::delete('conversations/{conversationId}/labels/{labelId}', [ConversationController::class, 'removeConversationLabel']);

// ── PRIORIDAD ─────────────────────────────────────────────────────────────────
Route::patch('conversations/{conversationId}/priority', [ConversationController::class, 'updatePriority']);
Route::patch('conversations/{conversationId}/bot-pause', [ConversationController::class, 'toggleBotPause']);

// ── DASHBOARD MÉTRICAS ────────────────────────────────────────────────────────
Route::get('crm/dashboard', [ConversationController::class, 'dashboard']);

// ── BROADCAST ─────────────────────────────────────────────────────────────────
Route::get('crm/broadcast/customers', [ConversationController::class, 'broadcastCustomers']);
Route::post('crm/broadcast', [ConversationController::class, 'sendBroadcast']);

// ── NUEVA CONVERSACIÓN ────────────────────────────────────────────────────────
Route::post('conversations', [ConversationController::class, 'createConversation']);

// ── ESTADO DE SERVICIO ────────────────────────────────────────────────────────
Route::get('conversations/{conversationId}/service-status', [ConversationController::class, 'serviceStatus'])
    ->withoutMiddleware('jwt.verify');

// ── REENVIAR MENSAJE ──────────────────────────────────────────────────────────
Route::post('messages/forward', [ConversationController::class, 'forwardMessage']);

// ── STICKERS ─────────────────────────────────────────────────────────────────
Route::get('stickers', [ConversationController::class, 'getStickers']);
Route::post('stickers', [ConversationController::class, 'saveSticker']);
Route::delete('stickers/{stickerId}', [ConversationController::class, 'deleteSticker']);

// ── CREAR TICKET DESDE CONVERSACIÓN ──────────────────────────────────────────
Route::post('conversations/{conversationId}/ticket', [ConversationController::class, 'createTicketFromConversation']);
Route::get('crm/ticket-meta', [ConversationController::class, 'ticketMeta']);

// ── ACTUALIZAR NOMBRE CLIENTE ─────────────────────────────────────────────────
Route::patch('conversations/{conversationId}/customer', [ConversationController::class, 'updateCustomerName']);

    // Route::get('/management/getOntInfo/{id}', [ManagementRouterController::class, 'getOntInfo']);


});