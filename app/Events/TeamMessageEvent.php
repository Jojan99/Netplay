<?php

namespace App\Events;

use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

/** Mensaje del chat interno: al canal del destinatario (y del emisor, para sus otras pestañas) o al general. */
class TeamMessageEvent implements ShouldBroadcast
{
    public function __construct(public array $message) {}

    public function broadcastOn()
    {
        if (empty($this->message['to_user_id'])) {
            return [new PresenceChannel('company.' . $this->message['company_id'])];
        }
        return [new PrivateChannel('user.' . $this->message['to_user_id']), new PrivateChannel('user.' . $this->message['from_user_id'])];
    }

    public function broadcastAs() { return 'team.message'; }
    public function broadcastWith() { return ['message' => $this->message]; }
}
