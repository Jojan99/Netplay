<?php

use App\Http\Controllers\Crm\ConversationController;
use App\Http\Controllers\ManagementRouterController;
use App\Http\Controllers\OltAdminController;
use App\Http\Controllers\VpnController;
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

    // Clientes que comparten una misma IP. Sólo lee lo que la plataforma tiene
    // registrado, así que exige sesión como cualquier pantalla del panel.
    Route::get('ip-conflicts', [ManagementRouterController::class, 'ipConflicts']);

    // Foto del modelo de router, para la ficha del equipo.
    Route::get('router-photo', [ManagementRouterController::class, 'routerPhoto']);

    // Todo lo que el router sabe de un puerto.
    Route::get('port-detail', [ManagementRouterController::class, 'portDetail']);

    // PPPoE: qué tiene preparado el router y quién está conectado.
    Route::get('pppoe', [ManagementRouterController::class, 'pppoeEstado']);

    // Estas escriben en el router y cambian el servicio de clientes reales.
    Route::middleware('role:admin')->group(function () {
        Route::get('pppoe/opciones',  [ManagementRouterController::class, 'pppoeOpciones']);
        Route::post('pppoe/montar',   [ManagementRouterController::class, 'pppoeMontar']);
        Route::get('pppoe/que-se-borra', [ManagementRouterController::class, 'pppoeQueSeBorra']);
        Route::post('pppoe/desmontar',   [ManagementRouterController::class, 'pppoeDesmontar']);

        // Crear, editar y borrar perfiles, rangos y servidores desde el panel.
        Route::post('pppoe/guardar',  [ManagementRouterController::class, 'pppoeGuardar']);
        Route::post('pppoe/eliminar', [ManagementRouterController::class, 'pppoeEliminar']);
        Route::post('cambiar-conexion', [ManagementRouterController::class, 'cambiarConexion']);
    });

    // Copia al sistema la IP que cada cliente tiene en el router. Escribe sobre
    // los clientes, así que va con rol admin.
    Route::post('sync-ips', [ManagementRouterController::class, 'syncIps'])->middleware('role:admin');

    // Separa los registros de IP que varios clientes comparten. No toca el
    // router, pero reescribe la asignación de esos clientes: va con admin.
    Route::post('separar-fichas', [ManagementRouterController::class, 'separarFichas'])->middleware('role:admin');
    Route::post('autorizarServicio', [ManagementRouterController::class, 'autorizarServicio'])->withoutMiddleware('jwt.verify');
    Route::post('migrarIp', [ManagementRouterController::class, 'migrarIp'])->withoutMiddleware('jwt.verify');

    // ── VPN de gestión ─────────────────────────────────────────────────────
    // El túnel por el que se llega a equipos que están en redes privadas, sin
    // depender de abrir una sesión SSH en el router del cliente.
    Route::prefix('vpn')->group(function () {
        Route::get('/estado',            [VpnController::class, 'estado']);
        Route::get('/instalador',        [VpnController::class, 'instalador']);
        Route::post('/tuneles',          [VpnController::class, 'crearTunel']);
        Route::get('/tuneles/{id}/script', [VpnController::class, 'script']);
        Route::put('/tuneles/{id}',      [VpnController::class, 'actualizarTunel']);
        Route::delete('/tuneles/{id}',   [VpnController::class, 'eliminarTunel']);
        Route::post('/tuneles/{id}/usar-en-olt', [VpnController::class, 'usarEnOlt']);
        Route::post('/tuneles/{id}/probar',      [VpnController::class, 'probar']);
    });

    // ── OLT Admin ──────────────────────────────────────────────────────────
    Route::prefix('olt')->group(function () {
        Route::get('/',                    [OltAdminController::class, 'index']);
        Route::post('/',                   [OltAdminController::class, 'store']);
        Route::put('/{id}',                [OltAdminController::class, 'update']);
        Route::delete('/{id}',             [OltAdminController::class, 'destroy']);
        Route::get('/marcas',              [OltAdminController::class, 'marcas']);

        // Ficha del equipo: marca, modelo, tarjetas y puertos, por SNMP.
        Route::get('/{oltId}/equipo',       [OltAdminController::class, 'equipo']);
        Route::get('/{oltId}/senal',        [OltAdminController::class, 'senal']);
        Route::get('/{oltId}/auto-autorizacion',  [OltAdminController::class, 'autoAutorizacion']);
        Route::post('/{oltId}/auto-autorizacion', [OltAdminController::class, 'cambiarAutoAutorizacion']);
        Route::get('/{oltId}/diagnostico',  [OltAdminController::class, 'diagnostico']);
        Route::post('/{oltId}/olvidar-puertos', [OltAdminController::class, 'olvidarPuertos']);
        Route::post('/{oltId}/foto',        [OltAdminController::class, 'guardarFoto']);
        Route::delete('/{oltId}/foto',      [OltAdminController::class, 'borrarFoto']);

        Route::get('/{oltId}/unauth',      [OltAdminController::class, 'unauthONTs']);
        Route::post('/{oltId}/register',   [OltAdminController::class, 'registerONT']);

        // Antes de autorizar: ¿esta ONT ya está en otro puerto?
        Route::get('/{oltId}/buscar-ont',      [OltAdminController::class, 'buscarOnt']);
        Route::post('/{oltId}/mover-ont',      [OltAdminController::class, 'moverOnt']);
        Route::get('/{oltId}/onts-incompletas', [OltAdminController::class, 'ontsIncompletas']);
        Route::get('/{oltId}/clientes-sin-ont',  [OltAdminController::class, 'clientesSinOnt']);
        Route::post('/{oltId}/completar-service-port', [OltAdminController::class, 'completarServicePort']);
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
        Route::post('/{oltId}/profiles/default', [OltAdminController::class, 'fijarPerfiles']);
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

    // 🗑️ Borrar y ✏️ editar un mensaje ya enviado (solo WhatsApp Web)
    Route::delete(
        'conversations/{conversationId}/messages/{messageId}',
        [ConversationController::class, 'deleteMessage']
    );
    Route::put(
        'conversations/{conversationId}/messages/{messageId}',
        [ConversationController::class, 'editMessage']
    );

    // 📥 Webhook WhatsApp
    Route::post(
        'receiveMessage',
        [ConversationController::class, 'receiveMessage']
    )->withoutMiddleware('jwt.verify');

    // 🧾 Comprobante de pago recibido por WhatsApp Web.
    // Lo manda el servicio Node máquina a máquina, firmado con la clave
    // maestra: no hay sesión de usuario detrás de un mensaje entrante.
    Route::post(
        'wa-proof',
        [\App\Http\Controllers\Crm\ComprobanteWaWebController::class, 'store']
    )->withoutMiddleware('jwt.verify')->middleware(['clave.maestra', 'throttle:120,1']);

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

// ── AUDIO M4A PARA SAFARI (lo carga <audio src>, sin cabeceras JWT) ───────────
Route::get('crm/media/m4a', [ConversationController::class, 'transcodeAudio'])->withoutMiddleware('jwt.verify');

// ── FICHA DEL CLIENTE EN EL CHAT ─────────────────────────────────────────────
Route::get('conversations/{conversationId}/customer-summary', [\App\Http\Controllers\Crm\CrmCustomerController::class, 'summary']);
Route::get('conversations/{conversationId}/history', [\App\Http\Controllers\Crm\CrmCustomerController::class, 'history']);
Route::post('conversations/{conversationId}/send-invoice', [\App\Http\Controllers\Crm\CrmCustomerController::class, 'sendInvoice']);
Route::post('conversations/{conversationId}/pay-link', [\App\Http\Controllers\Crm\CrmCustomerController::class, 'payLink']);
Route::post('conversations/{conversationId}/tech-note', [\App\Http\Controllers\Crm\CrmCustomerController::class, 'techNote']);

// ── EQUIPO: chat interno y llamadas entre agentes ────────────────────────────
Route::get('team/members', [\App\Http\Controllers\Crm\TeamController::class, 'members']);
Route::get('team/messages', [\App\Http\Controllers\Crm\TeamController::class, 'messages']);
Route::post('team/messages', [\App\Http\Controllers\Crm\TeamController::class, 'send']);
Route::post('team/read', [\App\Http\Controllers\Crm\TeamController::class, 'read']);
Route::post('team/call/signal', [\App\Http\Controllers\Crm\TeamController::class, 'callSignal']);
Route::get('team/ice', [\App\Http\Controllers\Crm\TeamController::class, 'ice']);

// ── CONFIGURACIÓN DEL CRM ────────────────────────────────────────────────────
Route::get('crm/settings', [ConversationController::class, 'getSettings']);

// Grupos de WhatsApp (solo WhatsApp Web; Meta no soporta grupos)
Route::get('crm/grupos',  [\App\Http\Controllers\Crm\GruposController::class, 'index']);
Route::post('crm/grupos', [\App\Http\Controllers\Crm\GruposController::class, 'toggle']);
Route::post('crm/settings', [ConversationController::class, 'saveSettings']);

// ── RESPUESTAS RÁPIDAS ("/atajo" en el chat) ──────────────────────────────────
Route::get('crm/quick-replies', [ConversationController::class, 'getQuickReplies']);
Route::post('crm/quick-replies', [ConversationController::class, 'saveQuickReply']);
Route::delete('crm/quick-replies/{id}', [ConversationController::class, 'deleteQuickReply']);

// ── CREAR TICKET DESDE CONVERSACIÓN ──────────────────────────────────────────
Route::post('conversations/{conversationId}/ticket', [ConversationController::class, 'createTicketFromConversation']);
Route::get('crm/ticket-meta', [ConversationController::class, 'ticketMeta']);

// ── ACTUALIZAR NOMBRE CLIENTE ─────────────────────────────────────────────────
Route::patch('conversations/{conversationId}/customer', [ConversationController::class, 'updateCustomerName']);

    // Route::get('/management/getOntInfo/{id}', [ManagementRouterController::class, 'getOntInfo']);


});