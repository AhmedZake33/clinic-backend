<?php

namespace App\Http\Controllers;

use App\Events\ReservationUpdated;
use App\Http\Traits\ResolvesDoctor;
use App\Models\DoctorAvailability;
use App\Models\Reservation;
use App\Models\ReservationLog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CheckInController extends Controller
{
    use ResolvesDoctor;

    /**
     * Check in a patient for their reservation.
     * Assigns a waiting number based on the daily queue for that doctor.
     */
    public function checkIn(Request $request, Reservation $reservation)
    {
        $doctorIds = $this->getDoctorIds($request);

        if (!in_array($reservation->doctor_id, $doctorIds)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        if ($reservation->checked_in_at) {
            return response()->json([
                'error' => 'Patient is already checked in',
                'waiting_number' => $reservation->waiting_number,
            ], 422);
        }

        if (!in_array($reservation->status, ['pending', 'confirmed'])) {
            return response()->json([
                'error' => 'Only pending or confirmed reservations can be checked in',
            ], 422);
        }

        // Check-in belongs to the day of the appointment
        $today = Carbon::today()->toDateString();
        if (Carbon::parse($reservation->appointment_date)->toDateString() !== $today) {
            return response()->json([
                'error' => 'Check-in is only available on the day of the appointment',
            ], 422);
        }

        $doctorId = $reservation->doctor_id;

        // Lock the doctor row so two simultaneous check-ins can't get the same number
        $waitingNumber = DB::transaction(function () use ($reservation, $doctorId, $today) {
            \App\Models\User::whereKey($doctorId)->lockForUpdate()->first();

            if (Reservation::whereKey($reservation->id)->whereNotNull('checked_in_at')->exists()) {
                return null; // checked in by someone else a moment ago
            }

            // Numbers keep increasing through the day. Completed patients count too,
            // otherwise a new check-in could reuse a number that was already given out.
            $maxNumber = Reservation::where('doctor_id', $doctorId)
                ->whereDate('appointment_date', $today)
                ->whereNotNull('checked_in_at')
                ->max('waiting_number');

            $maxPosition = Reservation::where('doctor_id', $doctorId)
                ->whereDate('appointment_date', $today)
                ->whereNotNull('checked_in_at')
                ->max('position');

            $number = ($maxNumber ?? 0) + 1;

            $reservation->update([
                'checked_in_at' => now(),
                'waiting_number' => $number,
                'position' => ($maxPosition ?? 0) + 1,
                'status' => $reservation->status === 'pending' ? 'confirmed' : $reservation->status,
            ]);

            return $number;
        });

        if ($waitingNumber === null) {
            return response()->json(['error' => 'Patient is already checked in'], 422);
        }

        ReservationLog::create([
            'reservation_id' => $reservation->id,
            'user_id' => $request->user()?->id,
            'action' => 'checked_in',
            'description' => 'Patient checked in',
            'meta' => ['waiting_number' => $waitingNumber],
        ]);

        $reservation->load(['client', 'doctor', 'creator']);
        broadcast(new ReservationUpdated($reservation))->toOthers();

        return response()->json([
            'message' => 'Patient checked in successfully',
            'reservation' => $reservation,
            'waiting_number' => $waitingNumber,
        ]);
    }

    /**
     * Undo check-in for a reservation.
     */
    public function undoCheckIn(Request $request, Reservation $reservation)
    {
        $doctorIds = $this->getDoctorIds($request);

        if (!in_array($reservation->doctor_id, $doctorIds)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if (!$reservation->checked_in_at) {
            return response()->json(['error' => 'Patient is not checked in'], 422);
        }

        if ($reservation->status === 'completed') {
            return response()->json(['error' => 'Cannot undo check-in for a completed reservation'], 422);
        }

        $reservation->update([
            'checked_in_at' => null,
            'waiting_number' => null,
            'position' => null,
        ]);

        ReservationLog::create([
            'reservation_id' => $reservation->id,
            'user_id' => $request->user()?->id,
            'action' => 'check_in_undone',
            'description' => 'Check-in undone',
        ]);

        $reservation->load(['client', 'doctor', 'creator']);
        broadcast(new ReservationUpdated($reservation))->toOthers();

        return response()->json([
            'message' => 'Check-in undone successfully',
            'reservation' => $reservation,
        ]);
    }

    /**
     * Get the waiting queue for today (or specified date) for a specific doctor.
     * Shows checked-in patients ordered by waiting number.
     */
    public function waitingQueue(Request $request)
    {
        $doctorIds = $this->getDoctorIds($request);
        $date = $request->query('date', Carbon::today()->toDateString());

        $query = Reservation::with(['client', 'doctor'])
            ->whereDate('appointment_date', $date)
            ->whereNotNull('checked_in_at')
            ->whereNotIn('status', ['cancelled', 'no_show']);

        $selectedDoctorId = $request->query('doctor_id');
        if ($selectedDoctorId && in_array((int)$selectedDoctorId, $doctorIds)) {
            $query->where('doctor_id', (int)$selectedDoctorId);
            $primaryDoctorId = (int)$selectedDoctorId;
        } else {
            $query->whereIn('doctor_id', $doctorIds);
            $primaryDoctorId = $doctorIds[0] ?? null;
        }

        // Order: non-completed first by waiting number, then completed at bottom
        // Prefer explicit `position` when present, otherwise fall back to `waiting_number`.
        $queue = $query->orderByRaw("CASE WHEN status = 'completed' THEN 1 ELSE 0 END ASC")
            ->orderByRaw("CASE WHEN position IS NULL THEN 1 ELSE 0 END ASC")
            ->orderBy('position', 'asc')
            ->orderBy('waiting_number', 'asc')
            ->get();

        // Calculate estimated wait times
        $avgMinutes = $this->getAverageConsultationTime($primaryDoctorId);
        $now = Carbon::now();

        // Serving starts per doctor at that doctor's first reservation time of the day
        // (earliest non-cancelled appointment, checked in or not).
        $startTimes = Reservation::query()
            ->whereDate('appointment_date', $date)
            ->where('status', '!=', 'cancelled')
            ->whereIn('doctor_id', $queue->pluck('doctor_id')->unique()->values())
            ->groupBy('doctor_id')
            ->selectRaw('doctor_id, MIN(appointment_date) as first_at')
            ->pluck('first_at', 'doctor_id')
            ->map(fn ($v) => Carbon::parse($v));

        $currentlyServing = null;
        $servingByDoctor = [];   // doctor_id => true once someone is being served
        $waitingAhead = [];      // doctor_id => number of waiting patients before
        $waitingItems = [];

        foreach ($queue as $reservation) {
            $item = $reservation->toArray();
            $item['is_current'] = false;
            $doctorId = $reservation->doctor_id;
            $startsAt = $startTimes[$doctorId] ?? Carbon::parse($reservation->appointment_date);
            $hasStarted = $now->greaterThanOrEqualTo($startsAt);

            if ($reservation->status === 'completed') {
                $item['estimated_wait'] = null;
                $item['queue_status'] = 'completed';
            } elseif ($hasStarted && empty($servingByDoctor[$doctorId])) {
                $servingByDoctor[$doctorId] = true;
                $currentlyServing ??= $reservation;
                $item['is_current'] = true;
                $item['estimated_wait'] = 0;
                $item['queue_status'] = 'serving';
            } else {
                $ahead = $waitingAhead[$doctorId] ?? 0;
                $waitingAhead[$doctorId] = $ahead + 1;

                if ($hasStarted) {
                    // One patient is in the room, plus everyone waiting ahead
                    $item['estimated_wait'] = ($ahead + 1) * $avgMinutes;
                } else {
                    // Nobody served yet: time until the first reservation, plus those ahead
                    $item['estimated_wait'] = (int) ceil($now->diffInSeconds($startsAt) / 60) + $ahead * $avgMinutes;
                }
                $item['queue_status'] = 'waiting';
            }

            $item['serving_starts_at'] = $startsAt->format('Y-m-d H:i:s');
            $waitingItems[] = $item;
        }

        // Earliest start among doctors in this view (for the "Now serving" card)
        $firstStart = $startTimes->sort()->first();

        // Doctors available for filtering
        $doctors = \App\Models\User::whereIn('id', $doctorIds)
            ->select('id', 'name', 'role')
            ->orderBy('name')
            ->get();

        // Stats
        $stats = [
            'total_checked_in' => $queue->count(),
            'waiting' => collect($waitingItems)->where('queue_status', 'waiting')->count(),
            'completed' => collect($waitingItems)->where('queue_status', 'completed')->count(),
            'avg_consultation_minutes' => $avgMinutes,
            'currently_serving' => $currentlyServing?->waiting_number,
            'serving_started' => $firstStart ? $now->greaterThanOrEqualTo($firstStart) : false,
            'serving_starts_at' => $firstStart?->format('Y-m-d H:i:s'),
        ];

        return response()->json([
            'queue' => $waitingItems,
            'stats' => $stats,
            'date' => $date,
            'doctors' => $doctors,
        ]);
    }

    /**
     * Get today's queue summary for all doctors (for assistant dashboard).
     */
    public function queueSummary(Request $request)
    {
        $doctorIds = $this->getDoctorIds($request);
        $date = $request->query('date', Carbon::today()->toDateString());

        $summary = DB::table('reservations')
            ->join('users', 'reservations.doctor_id', '=', 'users.id')
            ->whereDate('reservations.appointment_date', $date)
            ->whereNotNull('reservations.checked_in_at')
            ->whereNotIn('reservations.status', ['cancelled', 'no_show'])
            ->whereIn('reservations.doctor_id', $doctorIds)
            ->select(
                'reservations.doctor_id',
                'users.name as doctor_name',
                DB::raw("COUNT(*) as total_checked_in"),
                DB::raw("SUM(CASE WHEN reservations.status = 'completed' THEN 1 ELSE 0 END) as completed"),
                DB::raw("SUM(CASE WHEN reservations.status != 'completed' THEN 1 ELSE 0 END) as waiting"),
                DB::raw("MAX(reservations.waiting_number) as last_number")
            )
            ->groupBy('reservations.doctor_id', 'users.name')
            ->get();

        return response()->json([
            'summary' => $summary,
            'date' => $date,
        ]);
    }

    /**
     * Reorder the waiting queue. Accepts an array `ordered_ids` with reservation ids in the desired order.
     */
    public function reorderWaitingQueue(Request $request)
    {
        $doctorIds = $this->getDoctorIds($request);

        $data = $request->validate([
            'ordered_ids' => 'required|array',
            'ordered_ids.*' => 'integer|exists:reservations,id',
        ]);

        // Only patients that are checked in and still waiting, inside the caller's clinic
        $reservations = Reservation::query()
            ->whereIn('id', $data['ordered_ids'])
            ->whereIn('doctor_id', $doctorIds)
            ->whereNotNull('checked_in_at')
            ->where('status', '!=', 'completed')
            ->get()
            ->keyBy('id');

        $orderedIds = array_values(array_filter($data['ordered_ids'], fn ($id) => $reservations->has($id)));

        if (empty($orderedIds)) {
            return response()->json(['error' => 'No valid reservations to reorder'], 422);
        }

        // Only `position` changes. `waiting_number` is the patient's ticket number and stays the same.
        DB::transaction(function () use ($orderedIds) {
            foreach ($orderedIds as $index => $id) {
                DB::table('reservations')->where('id', $id)->update(['position' => $index + 1]);
            }
        });

        // Tell each affected doctor's screens (doctor + sub-doctors) to refresh
        foreach (collect($orderedIds)->groupBy(fn ($id) => $reservations[$id]->doctor_id) as $doctorId => $ids) {
            event(new \App\Events\QueueReordered((int) $doctorId, $ids->values()->all()));
        }

        return response()->json(['message' => 'Queue reordered successfully']);
    }

    /**
     * Calculate average consultation time for a doctor based on availability slot duration or default.
     */
    private function getAverageConsultationTime(?int $doctorId): int
    {
        if (!$doctorId) {
            return 15;
        }

        return DoctorAvailability::where('user_id', $doctorId)
            ->value('slot_duration_minutes') ?? 15;
    }
}
