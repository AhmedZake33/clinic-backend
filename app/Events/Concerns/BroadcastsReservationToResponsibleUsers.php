<?php

namespace App\Events\Concerns;

use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;

trait BroadcastsReservationToResponsibleUsers
{
    /**
     * @return array<int, PrivateChannel>
     */
    protected function reservationChannelsForDoctor(int $doctorId): array
    {
        $clinicDoctorId = $this->clinicDoctorIdFor($doctorId);

        return [
            new PrivateChannel('doctor.' . $doctorId),
            new PrivateChannel('assistant.reservations.' . $clinicDoctorId),
        ];
    }

    protected function clinicDoctorIdFor(int $doctorId): int
    {
        $doctor = User::query()
            ->select(['id', 'role', 'parent_doctor_id'])
            ->find($doctorId);

        if ($doctor?->role === 'sub-doctor' && $doctor->parent_doctor_id) {
            return (int) $doctor->parent_doctor_id;
        }

        return $doctorId;
    }
}
