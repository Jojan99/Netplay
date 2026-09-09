<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Un mensaje ya existente cambió: se borró o se editó.
 *
 * Va por el mismo canal privado de la conversación que el resto del chat, para
 * que el panel lo aplique sin recargar.
 */
class MessageRevisedEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $conversationId,
        public int $messageId,
        /** 'deleted' | 'edited' */
        public string $accion,
        public ?string $content = null,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('conversation.' . $this->conversationId)];
    }

    public function broadcastAs(): string
    {
        return 'message.revised';
    }

    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'message_id'      => $this->messageId,
            'accion'          => $this->accion,
            'content'         => $this->content,
        ];
    }
}
