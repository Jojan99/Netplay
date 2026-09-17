<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class ConversationClosedEvent implements ShouldBroadcast
{
    public function __construct(
        public int $conversationId,
        public ?int $companyId = null
    ) {
        $this->companyId ??= (int) \Illuminate\Support\Facades\DB::table('crm_conversations')
            ->where('id', $conversationId)->value('company_id') ?: null;
    }

    public function broadcastOn()
    {
        // 'crm.inbox' global solo por compatibilidad; el de la empresa es el que debe usarse.
        $channels = [new PrivateChannel('crm.inbox')];
        if ($this->companyId) $channels[] = new PrivateChannel('crm.inbox.' . $this->companyId);
        return $channels;
    }

    public function broadcastWith()
    {
        return ['conversationId' => $this->conversationId];
    }

    public function broadcastAs()
    {
        return 'conversation.closed';
    }
}
