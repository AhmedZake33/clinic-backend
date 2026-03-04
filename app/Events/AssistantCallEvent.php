<?php

namespace App\Events;

use App\Models\AssistantCall;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AssistantCallEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public AssistantCall $call;
    public string $action; // 'created', 'accepted', 'completed'

    public function __construct(AssistantCall $call, string $action)
    {
        $this->call = $call->load(['doctor', 'assistant']);
        $this->action = $action;
    }

    /**
     * Broadcast to:
     * - The assistant channel (for the clinic) so all assistants see new calls
     * - The doctor's private channel so doctor gets status updates
     */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('clinic.' . $this->call->clinic_id . '.assistant-calls'),
            new PrivateChannel('doctor.' . $this->call->doctor_id),
        ];

        // Also broadcast to the specific assistant's private channel
        if ($this->call->assistant_id) {
            $channels[] = new PrivateChannel('assistant.' . $this->call->assistant_id);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'assistant.call';
    }

    public function broadcastWith(): array
    {
        return [
            'action' => $this->action,
            'call' => [
                'id' => $this->call->id,
                'doctor_id' => $this->call->doctor_id,
                'assistant_id' => $this->call->assistant_id,
                'clinic_id' => $this->call->clinic_id,
                'status' => $this->call->status,
                'message' => $this->call->message,
                'created_at' => $this->call->created_at?->toISOString(),
                'updated_at' => $this->call->updated_at?->toISOString(),
                'doctor' => $this->call->doctor ? [
                    'id' => $this->call->doctor->id,
                    'name' => $this->call->doctor->name,
                ] : null,
                'assistant' => $this->call->assistant ? [
                    'id' => $this->call->assistant->id,
                    'name' => $this->call->assistant->name,
                ] : null,
            ],
        ];
    }
}
