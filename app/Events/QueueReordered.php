<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class QueueReordered implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $doctorId;
    public array $orderedIds;

    public function __construct(int $doctorId, array $orderedIds)
    {
        $this->doctorId = $doctorId;
        $this->orderedIds = $orderedIds;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('doctor.' . $this->doctorId),
            new PrivateChannel('assistant.reservations'),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'doctor_id' => $this->doctorId,
            'ordered_ids' => $this->orderedIds,
            'type' => 'reordered',
            'message' => 'Queue reordered',
        ];
    }

    public function broadcastAs(): string
    {
        return 'queue.reordered';
    }
}
