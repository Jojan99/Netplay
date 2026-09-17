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
        public ?int $companyId = null        // si no llega se toma de la conversación
    ) {
        $this->companyId ??= (int) \Illuminate\Support\Facades\DB::table('crm_conversations')
            ->where('id', $conversationId)->value('company_id') ?: null;
    }

    public function broadcastOn()
    {
        // 'crm.inbox' es global y lo escuchan todas las empresas: queda solo por
        // compatibilidad con el panel compilado. El canal correcto es el de la empresa.
        $channels = [new PrivateChannel('crm.inbox')];
        if ($this->companyId) $channels[] = new PrivateChannel('crm.inbox.' . $this->companyId);
        return $channels;
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
        ];
    }
}
