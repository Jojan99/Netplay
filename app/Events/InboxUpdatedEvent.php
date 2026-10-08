<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class InboxUpdatedEvent implements ShouldBroadcast
{
    public function __construct(
        public int $conversationId,
        public string $status,
        public string $sender = 'customer',  // customer|agent: el panel suena sólo con mensajes del cliente
        public ?string $provider = null,     // meta|netplay: para avisar en la otra bandeja
        public ?int $companyId = null,       // si no llega se toma de la conversación
        public ?string $aviso = null         // algo que el asesor tiene que ver ya (el bot le pasó el chat)
    ) {
        $this->companyId ??= (int) \Illuminate\Support\Facades\DB::table('crm_conversations')
            ->where('id', $conversationId)->value('company_id') ?: null;
    }

    public function broadcastOn()
    {
        // Solo el canal de la empresa. 'crm.inbox' lo escuchaban todas las empresas y a
        // cada panel le llegaban los avisos (y los ids de conversación) de las demás.
        return $this->companyId ? [new PrivateChannel('crm.inbox.' . $this->companyId)] : [];
    }

    public function broadcastAs()
    {
        return 'inbox.updated';
    }

    public function broadcastWith()
    {
        return [
            'conversationId' => $this->conversationId,
            'status' => $this->status,
            'sender' => $this->sender,
            'provider' => $this->provider,
            'aviso' => $this->aviso,
            // El canal 'crm.inbox' lo escuchan todas las empresas: el panel compara esto
            // antes de mostrar un aviso, para no alertar a quien no es.
            'companyId' => $this->companyId,
        ];
    }
}
