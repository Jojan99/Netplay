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
        // Lleva el contenido del mensaje: nunca por 'crm.inbox', que escuchaban todas
        // las empresas. Solo por el canal de la empresa de la conversación.
        $empresa = (int) \Illuminate\Support\Facades\DB::table('crm_conversations')->where('id', $this->conversationId)->value('company_id');

        return $empresa ? [new PrivateChannel('crm.inbox.' . $empresa)] : [];
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
