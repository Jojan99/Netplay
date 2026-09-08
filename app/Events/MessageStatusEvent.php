<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

/** Ack de WhatsApp (enviado / entregado / leído / fallido) para un mensaje del agente. */
class MessageStatusEvent implements ShouldBroadcast
{
    public function __construct(
        public int $conversationId,
        public int $messageId,
        public string $status
    ) {}

    public function broadcastOn()
    {
        return new PrivateChannel('conversation.' . $this->conversationId);
    }

    public function broadcastAs()
    {
        return 'message.status';
    }

    public function broadcastWith()
    {
        return ['conversationId' => $this->conversationId, 'messageId' => $this->messageId, 'status' => $this->status];
    }
}
