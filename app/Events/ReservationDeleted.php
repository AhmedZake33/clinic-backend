<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ReservationDeleted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $reservationId;
    public int $doctorId;
    public string $clientName;

    /**
     * Create a new event instance.
     */
    public function __construct(int $reservationId, int $doctorId, string $clientName)
    {
        $this->reservationId = $reservationId;
        $this->doctorId = $doctorId;
        $this->clientName = $clientName;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('doctor.' . $this->doctorId),
            new PrivateChannel('assistant.reservations'),
        ];
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'reservation_id' => $this->reservationId,
            'doctor_id' => $this->doctorId,
            'type' => 'deleted',
            'message' => 'Reservation deleted for ' . $this->clientName,
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'reservation.deleted';
    }
}
