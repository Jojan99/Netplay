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
        // Solo el canal de la empresa: 'crm.inbox' lo escuchaban todas.
        $channels = [];
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
