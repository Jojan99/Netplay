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
