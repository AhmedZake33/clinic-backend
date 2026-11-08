<?php

namespace App\Services\Reservations;

use App\Models\Reservation;
use App\Models\ReservationLog;
use App\Models\User;

class ReservationLogService
{
    public function log(
        Reservation $reservation,
        ?User $actor,
        string $action,
        ?string $description = null,
        array $meta = []
    ): void {
        ReservationLog::create([
            'reservation_id' => $reservation->id,
            'user_id' => $actor?->id,
            'action' => $action,
            'description' => $description,
            'meta' => $meta ?: null,
        ]);
    }
}
