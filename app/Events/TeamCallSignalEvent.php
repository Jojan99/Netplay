<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

/** Señalización WebRTC de llamadas entre agentes (offer/answer/ice/ring/accept/reject/hangup/busy). */
class TeamCallSignalEvent implements ShouldBroadcast
{
    public function __construct(public int $toUserId, public array $signal) {}
    public function broadcastOn() { return new PrivateChannel('user.' . $this->toUserId); }
    public function broadcastAs() { return 'team.call'; }
    public function broadcastWith() { return $this->signal; }
}
