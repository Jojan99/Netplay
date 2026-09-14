<?php

use Illuminate\Support\Facades\Log;

use Illuminate\Support\Facades\Broadcast;

// Cambia de 'crm.inbox' a 'conversation.{conversationId}'
Broadcast::channel('conversation.{conversationId}', function ($user, $conversationId) {
    // Las conversaciones del CRM son del equipo: un cliente con su token del
    // portal podía suscribirse y leer los mensajes en vivo.
    if (\App\Http\Middleware\JwtMiddleware::esCliente($user)) return false;

    return ['id' => $user->id, 'name' => $user->name];
    
    // O si quieres verificar permisos:
    // return $user->conversations()->where('id', $conversationId)->exists();
});

Broadcast::channel('crm.inbox', function ($user) {
    // Sólo el equipo: antes aceptaba a cualquier usuario autenticado.
    return !\App\Http\Middleware\JwtMiddleware::esCliente($user);
});

// Presencia por empresa: quién del equipo está en línea (chat interno y llamadas)
Broadcast::channel('company.{companyId}', function ($user, $companyId) {
    if ((int) $user->company_id !== (int) $companyId) return false;
    // La presencia del equipo no es para los clientes de la empresa.
    if (\App\Http\Middleware\JwtMiddleware::esCliente($user)) return false;
    $ud = \Illuminate\Support\Facades\DB::table('user_data')->where('user_id', $user->id)->first(['names', 'lastname']);
    return ['id' => $user->id, 'name' => trim(($ud->names ?? '') . ' ' . ($ud->lastname ?? '')) ?: ($user->email ?? 'Usuario')];
});

// Canal privado por usuario: mensajes directos y señalización de llamadas
Broadcast::channel('user.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});
