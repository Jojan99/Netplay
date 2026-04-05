<?php

namespace App\Events;

use App\Models\CrmMessage;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Broadcasting\InteractsWithSockets;

class NewMessageEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public CrmMessage $message;
    public int $conversation_id; // ✅ FALTABA ESTO

    public function __construct(CrmMessage $message, int $conversationId)
    {
        $this->message = $message;
        $this->conversation_id = $conversationId;
    }

    /**
     * Canal donde se emite
     */
    public function broadcastOn()
    {
        return new PrivateChannel('conversation.' . $this->conversation_id);
    }

    /**
     * Nombre del evento
     */
    public function broadcastAs()
    {
        return 'message.new';
    }

    /**
     * Payload enviado al frontend
     */
    public function broadcastWith()
    {
        return [
            'conversationId' => $this->conversation_id,
            'message' => [
                'id' => $this->message->id,
                'conversation_id' => $this->conversation_id,
                'content' => $this->message->content,          // nombre archivo
                'sender_type' => $this->message->sender_type,
                'created_at' => $this->message->created_at,
                'message_type' => $this->message->message_type,
                'media_url' => $this->message->media_url,
                'mime_type' => $this->message->mime_type,      // ✅ NUEVO
            ],
        ];
    }
}
