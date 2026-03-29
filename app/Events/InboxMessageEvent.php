<?php

namespace App\Events;

use App\Models\CrmMessage;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Queue\SerializesModels;

class InboxMessageEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public CrmMessage $message,
        public int $conversationId
    ) {}

    public function broadcastOn()
    {
        return new PrivateChannel('crm.inbox');
    }

    public function broadcastAs()
    {
        return 'message.new';
    }

    public function broadcastWith()
    {
        return [
        'conversationId' => $this->conversationId,
        'message' => [
            'id' => $this->message->id,
            'conversation_id' => $this->message->conversation_id,
            'content' => $this->message->content,
            'sender_type' => $this->message->sender_type,
            'created_at' => $this->message->created_at,
        ],
    ];
    }
}
