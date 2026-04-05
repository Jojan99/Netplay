<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TypingEvent implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int $conversationId,
        public string $senderType // 'agent' | 'customer'
    ) {}

    public function broadcastOn()
    {
        return new PrivateChannel('conversation.' . $this->conversationId);
    }

    public function broadcastAs()
    {
        return 'typing';
    }

    public function broadcastWith()
    {
        return [
            'conversationId' => $this->conversationId,
            'senderType' => $this->senderType
        ];
    }
}
