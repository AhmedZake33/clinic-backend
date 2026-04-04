<?php

namespace App\Http\Controllers;

use App\Events\ReservationUpdated;
use App\Http\Traits\ResolvesDoctor;
use App\Models\DoctorAvailability;
use App\Models\Reservation;
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
        $doctorId = $this->requireDoctorId($request);

        if ($reservation->doctor_id !== $doctorId) {
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

        $today = Carbon::today()->toDateString();
        $doctorId = $reservation->doctor_id;

        // Get next waiting number and position for this doctor today
        $maxNumber = Reservation::where('doctor_id', $doctorId)
            ->whereDate('appointment_date', $today)
            ->whereNotNull('checked_in_at')
            ->where('status', '!=', 'completed')
            ->max('waiting_number');

        $maxPosition = Reservation::where('doctor_id', $doctorId)
            ->whereDate('appointment_date', $today)
            ->whereNotNull('checked_in_at')
            ->where('status', '!=', 'completed')
            ->max('position');

        $waitingNumber = ($maxNumber ?? 0) + 1;
        $position = ($maxPosition ?? 0) + 1;

        $reservation->update([
            'checked_in_at' => now(),
            'waiting_number' => $waitingNumber,
            'position' => $position,
            'status' => $reservation->status === 'pending' ? 'confirmed' : $reservation->status,
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
        $doctorId = $this->requireDoctorId($request);

        if ($reservation->doctor_id !== $doctorId) {
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
        $doctorId = $this->requireDoctorId($request);
        $date = $request->query('date', Carbon::today()->toDateString());

        $query = Reservation::with(['client', 'doctor'])
            ->whereDate('appointment_date', $date)
            ->whereNotNull('checked_in_at')
            ->where('doctor_id', $doctorId)
            ->where('status', '!=', 'cancelled');

        // Order: non-completed first by waiting number, then completed at bottom
        // Prefer explicit `position` when present, otherwise fall back to `waiting_number`.
        $queue = $query->orderByRaw("CASE WHEN status = 'completed' THEN 1 ELSE 0 END ASC")
            ->orderByRaw("CASE WHEN position IS NULL THEN 1 ELSE 0 END ASC")
            ->orderBy('position', 'asc')
            ->orderBy('waiting_number', 'asc')
            ->get();

        // Calculate estimated wait times
        $avgMinutes = $this->getAverageConsultationTime($doctorId);
        $currentlyServing = null;
        $waitingItems = [];

        foreach ($queue as $index => $reservation) {
            $item = $reservation->toArray();
            $item['is_current'] = false;

            if ($reservation->status === 'completed') {
                $item['estimated_wait'] = null;
                $item['queue_status'] = 'completed';
            } elseif ($currentlyServing === null && $reservation->status !== 'completed') {
                $currentlyServing = $reservation;
                $item['is_current'] = true;
                $item['estimated_wait'] = 0;
                $item['queue_status'] = 'serving';
            } else {
                // Count how many non-completed are before this one
                $ahead = collect($waitingItems)->where('queue_status', 'waiting')->count();
                $item['estimated_wait'] = ($ahead + 1) * $avgMinutes;
                $item['queue_status'] = 'waiting';
            }

            $waitingItems[] = $item;
        }

        // Stats
        $stats = [
            'total_checked_in' => $queue->count(),
            'waiting' => $queue->where('status', '!=', 'completed')->whereNull('completed_at')->count(),
            'completed' => $queue->where('status', 'completed')->count(),
            'avg_consultation_minutes' => $avgMinutes,
            'currently_serving' => $currentlyServing?->waiting_number,
        ];

        return response()->json([
            'queue' => $waitingItems,
            'stats' => $stats,
            'date' => $date,
        ]);
    }

    /**
     * Get today's queue summary for all doctors (for assistant dashboard).
     */
    public function queueSummary(Request $request)
    {
        $doctorId = $this->requireDoctorId($request);
        $date = $request->query('date', Carbon::today()->toDateString());

        $summary = DB::table('reservations')
            ->join('users', 'reservations.doctor_id', '=', 'users.id')
            ->whereDate('reservations.appointment_date', $date)
            ->whereNotNull('reservations.checked_in_at')
            ->where('reservations.status', '!=', 'cancelled')
            ->where('reservations.doctor_id', $doctorId)
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
        $doctorId = $this->requireDoctorId($request);

        $data = $request->validate([
            'ordered_ids' => 'required|array',
            'ordered_ids.*' => 'integer|exists:reservations,id',
        ]);

        $orderedIds = $data['ordered_ids'];

        DB::transaction(function () use ($orderedIds, $doctorId) {
            foreach ($orderedIds as $index => $id) {
                DB::table('reservations')
                    ->where('id', $id)
                    ->where('doctor_id', $doctorId)
                    ->update([
                        'position' => $index + 1,
                        'waiting_number' => $index + 1,
                    ]);
            }
        });

        // Broadcast a queue.reordered event so other clients refresh
        event(new \App\Events\QueueReordered($doctorId, $orderedIds));

        return response()->json(['message' => 'Queue reordered successfully']);
    }

    /**
     * Calculate average consultation time for a doctor based on recent completed reservations.
     */
    private function getAverageConsultationTime(?int $doctorId): int
    {
        return DoctorAvailability::where('user_id', $doctorId)
            ->select("slot_duration_minutes")->first()?->slot_duration_minutes ?? 15;
        $query = Reservation::whereNotNull('checked_in_at')
            ->whereNotNull('completed_at')
            ->where('status', 'completed');

        if ($doctorId) {
            $query->where('doctor_id', $doctorId);
        }

        $avg = $query->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, checked_in_at, completed_at)) as avg_minutes')
            ->value('avg_minutes');

        // Default to 15 minutes if no data, cap between 5 and 60
        $minutes = $avg ? round($avg) : 15;
        return max(5, min(60, $minutes));
    }
}
