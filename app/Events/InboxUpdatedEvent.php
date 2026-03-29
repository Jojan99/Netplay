<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class InboxUpdatedEvent implements ShouldBroadcast
{
    public function __construct(
        public int $conversationId,
        public string $status
    ) {}

    public function broadcastOn()
    {
        return new PrivateChannel('crm.inbox');
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
        ];
    }
}
