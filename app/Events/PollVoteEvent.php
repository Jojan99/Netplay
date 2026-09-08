<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

/** Votos actualizados de una encuesta (en tiempo real en el chat). */
class PollVoteEvent implements ShouldBroadcast
{
    public function __construct(public int $conversationId, public int $messageId, public array $votes) {}
    public function broadcastOn() { return new PrivateChannel('conversation.' . $this->conversationId); }
    public function broadcastAs() { return 'poll.vote'; }
    public function broadcastWith() { return ['conversationId' => $this->conversationId, 'messageId' => $this->messageId, 'votes' => $this->votes]; }
}
