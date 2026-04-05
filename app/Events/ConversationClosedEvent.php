<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class ConversationClosedEvent implements ShouldBroadcast
{
    public function __construct(
        public int $conversationId
    ) {}

    public function broadcastOn()
    {
        return new PrivateChannel('crm.inbox');
    }

    public function broadcastAs()
    {
        return 'conversation.closed';
    }
}
