<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Queue\SerializesModels;

class NewMessageEventPayload implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public array $message;
    public int $conversation_id;

    public function __construct(array $message, int $conversationId)
    {
        $this->message = $message;
        $this->conversation_id = $conversationId;
    }

    public function broadcastOn()
    {
        return new PrivateChannel('conversation.' . $this->conversation_id);
    }

    public function broadcastAs()
    {
        return 'message.new';
    }

    public function broadcastWith()
    {
        return [
            'conversationId' => $this->conversation_id,
            'message' => $this->message,
        ];
    }
}
