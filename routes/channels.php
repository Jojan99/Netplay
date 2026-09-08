<?php

use Illuminate\Support\Facades\Log;

use Illuminate\Support\Facades\Broadcast;

// Cambia de 'crm.inbox' a 'conversation.{conversationId}'
Broadcast::channel('conversation.{conversationId}', function ($user, $conversationId) {
    // Aquí puedes verificar si el usuario tiene acceso a esa conversación
    // Por ejemplo, verificar si es participante de la conversación
    
    return ['id' => $user->id, 'name' => $user->name];
    
    // O si quieres verificar permisos:
    // return $user->conversations()->where('id', $conversationId)->exists();
});

Broadcast::channel('crm.inbox', function ($user) {
    return true;
});

// Presencia por empresa: quién del equipo está en línea (chat interno y llamadas)
Broadcast::channel('company.{companyId}', function ($user, $companyId) {
    if ((int) $user->company_id !== (int) $companyId) return false;
    $ud = \Illuminate\Support\Facades\DB::table('user_data')->where('user_id', $user->id)->first(['names', 'lastname']);
    return ['id' => $user->id, 'name' => trim(($ud->names ?? '') . ' ' . ($ud->lastname ?? '')) ?: ($user->email ?? 'Usuario')];
});

// Canal privado por usuario: mensajes directos y señalización de llamadas
Broadcast::channel('user.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});
