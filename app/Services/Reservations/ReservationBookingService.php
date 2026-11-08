<?php

namespace App\Services\Reservations;

use App\Models\Client;
use App\Models\DoctorAvailability;
use App\Models\DoctorHoliday;
use App\Models\Financial;
use App\Models\Reservation;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Booking rules for reservations: schedule checks, status transitions,
 * initial financial record and queue position.
 */
class ReservationBookingService
{
    /**
     * Valid status transitions:
     *   pending   → confirmed, cancelled, no_show
     *   confirmed → completed, cancelled, no_show
     *   completed → (terminal)
     *   cancelled → (terminal)
     *   no_show   → (terminal)
     */
    public const STATUS_TRANSITIONS = [
        'pending'   => ['confirmed', 'cancelled', 'no_show'],
        'confirmed' => ['completed', 'cancelled', 'no_show'],
        'completed' => [],
        'cancelled' => [],
        'no_show'   => [],
    ];

    public const DEFAULT_SLOT_MINUTES = 30;

    public function isPastDate(Carbon $appointment): bool
    {
        return $appointment->toDateString() < Carbon::today()->toDateString();
    }

    /**
     * Check a new appointment against the doctor's schedule.
     * Returns an error message, or null when the slot is bookable.
     */
    public function slotError(User $doctor, int $doctorId, Carbon $appointment, ?int $ignoreReservationId = null): ?string
    {
        if ($this->isPastDate($appointment)) {
            return 'Reservations can only be created for today or future dates';
        }

        $dayOfWeek = (int) $appointment->dayOfWeek; // 0 (Sunday) to 6 (Saturday)

        // Holidays (specific date or recurring weekday)
        $isHoliday = DoctorHoliday::where('user_id', $doctor->id)
            ->where(function ($query) use ($appointment, $dayOfWeek) {
                $query->whereDate('date', $appointment->toDateString())
                    ->orWhere('recurring_day_of_week', $dayOfWeek);
            })
            ->exists();
        if ($isHoliday) {
            return 'Doctor is on holiday on selected date';
        }

        // Availability window for the day
        $timeStr = $appointment->format('H:i');
        $hasAvailability = DoctorAvailability::where('user_id', $doctor->id)
            ->where('day_of_week', $dayOfWeek)
            ->where('start_time', '<=', $timeStr)
            ->where('end_time', '>', $timeStr) // a slot can't start at the closing time
            ->exists();
        if (!$hasAvailability) {
            return 'Selected time is outside doctor availability';
        }

        if ($this->hasConflict($doctorId, $appointment, $ignoreReservationId)) {
            return 'Selected time is already booked';
        }

        return null;
    }

    /**
     * Length of one appointment for this doctor at this time (from the working window).
     */
    public function slotMinutes(int $doctorId, Carbon $appointment): int
    {
        $window = DoctorAvailability::where('user_id', $doctorId)
            ->where('day_of_week', (int) $appointment->dayOfWeek)
            ->where('start_time', '<=', $appointment->format('H:i'))
            ->where('end_time', '>', $appointment->format('H:i'))
            ->first();

        return max(5, (int) ($window?->slot_duration_minutes ?: self::DEFAULT_SLOT_MINUTES));
    }

    /**
     * True when another active (non-cancelled) reservation overlaps this appointment,
     * i.e. starts less than one slot before or after it. A 10:15 booking therefore
     * conflicts with a 10:00 one when slots are 30 minutes.
     */
    public function hasConflict(int $doctorId, Carbon $appointment, ?int $ignoreReservationId = null): bool
    {
        $minutes = $this->slotMinutes($doctorId, $appointment);

        return Reservation::where('doctor_id', $doctorId)
            ->whereNotIn('status', ['cancelled', 'no_show'])
            ->when($ignoreReservationId, fn ($q) => $q->where('id', '!=', $ignoreReservationId))
            ->where('appointment_date', '>', $appointment->copy()->subMinutes($minutes))
            ->where('appointment_date', '<', $appointment->copy()->addMinutes($minutes))
            ->exists();
    }

    /**
     * In-memory overlap test used when listing free slots for a whole day.
     */
    public static function overlapsAny(iterable $bookedTimes, Carbon $slot, int $minutes): bool
    {
        foreach ($bookedTimes as $booked) {
            if (abs($slot->diffInMinutes(Carbon::parse($booked), false)) < $minutes) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verify a client belongs to this doctor (or, for a sub-doctor, to the parent doctor).
     */
    public function clientAllowedFor(User $doctor, int $clientId): bool
    {
        $allowed = [$doctor->id];
        if ($doctor->role === 'sub-doctor' && $doctor->parent_doctor_id) {
            $allowed[] = $doctor->parent_doctor_id;
        }

        return Client::where('id', $clientId)->whereIn('doctor_id', $allowed)->exists();
    }

    /**
     * Returns an error message if the status change is not allowed, otherwise null.
     */
    public function transitionError(string $currentStatus, string $newStatus): ?string
    {
        if ($newStatus === $currentStatus) {
            return null;
        }

        $allowed = self::STATUS_TRANSITIONS[$currentStatus] ?? [];

        return in_array($newStatus, $allowed)
            ? null
            : "Cannot change status from {$currentStatus} to {$newStatus}";
    }

    /**
     * Create the reservation's financial record and, if something was paid, the first transaction.
     */
    public function createInitialFinancial(
        Reservation $reservation,
        int $clientId,
        int $doctorId,
        int $createdBy,
        float $amount,
        float $paid,
        string $paymentMethod
    ): void {
        $remaining = $amount - $paid;
        $paymentStatus = $paid <= 0 ? 'unpaid' : ($paid >= $amount ? 'paid' : 'partial');

        DB::transaction(function () use ($reservation, $clientId, $doctorId, $createdBy, $amount, $paid, $remaining, $paymentStatus, $paymentMethod) {
            $financial = Financial::create([
                'reservation_id' => $reservation->id,
                'client_id'      => $clientId,
                'doctor_id'      => $doctorId,
                'created_by'     => $createdBy,
                'amount'         => $amount,
                'paid'           => $paid,
                'remaining'      => $remaining,
                'payment_status' => $paymentStatus,
                'payment_method' => $paymentMethod,
                'notes'          => null,
            ]);

            if ($paid > 0) {
                Transaction::create([
                    'financial_id'   => $financial->id,
                    'doctor_id'      => $doctorId,
                    'created_by'     => $createdBy,
                    'amount'         => $paid,
                    'payment_method' => $paymentMethod,
                    'notes'          => null,
                ]);
            }
        });
    }

    /**
     * Number of active reservations before this one on the same day for the same doctor.
     */
    public function reservationsBefore(Reservation $reservation, Carbon $appointment): int
    {
        return Reservation::where('doctor_id', $reservation->doctor_id)
            ->whereDate('appointment_date', $appointment->toDateString())
            ->whereIn('status', ['pending', 'confirmed'])
            ->where('appointment_date', '<', $reservation->appointment_date)
            ->count();
    }
}
