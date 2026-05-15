<?php

namespace App\Http\Controllers;

use App\Models\DoctorAvailability;
use App\Models\DoctorHoliday;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class DoctorScheduleController extends Controller
{
    public function getAvailability(User $doctor)
    {
        if (!in_array($doctor->role, ['doctor', 'sub-doctor'])) {
            return response()->json(['error' => 'User is not a doctor'], 422);
        }

        $availability = DoctorAvailability::where('user_id', $doctor->id)
            ->orderBy('day_of_week')
            ->get();
        return response()->json($availability);
    }

    public function updateAvailability(Request $request, User $doctor)
    {
        if (!in_array($doctor->role, ['doctor', 'sub-doctor'])) {
            return response()->json(['error' => 'User is not a doctor'], 422);
        }

        if ($request->user()->id !== $doctor->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $request->validate([
            'slots' => 'required|array|min:0',
            'slots.*.day_of_week' => 'required|integer|min:0|max:6',
            'slots.*.start_time' => 'required|date_format:H:i',
            'slots.*.end_time' => 'required|date_format:H:i',
            'slot_duration_minutes' => 'nullable|integer|min:5|max:180',
        ]);

        foreach ($request->slots as $index => $slot) {
            if (($slot['end_time'] ?? '') <= ($slot['start_time'] ?? '')) {
                return response()->json([
                    'message' => 'Validation error',
                    'errors' => [
                        "slots.$index.end_time" => ['End time must be after start time'],
                    ],
                ], 422);
            }
        }

        $slotDurationMinutes = (int) $request->input('slot_duration_minutes', 30);
        $hasDurationColumn = Schema::hasColumn('doctor_availabilities', 'slot_duration_minutes');

        // Replace all availability for this doctor
        DoctorAvailability::where('user_id', $doctor->id)->delete();
        foreach ($request->slots as $slot) {
            $availabilityData = [
                'user_id' => $doctor->id,
                'day_of_week' => $slot['day_of_week'],
                'start_time' => $slot['start_time'],
                'end_time' => $slot['end_time'],
            ];

            if ($hasDurationColumn) {
                $availabilityData['slot_duration_minutes'] = $slotDurationMinutes;
            }

            DoctorAvailability::create($availabilityData);
        }

        $availability = DoctorAvailability::where('user_id', $doctor->id)
            ->orderBy('day_of_week')
            ->get();
        return response()->json($availability);
    }

    public function getHolidays(User $doctor)
    {
        if (!in_array($doctor->role, ['doctor', 'sub-doctor'])) {
            return response()->json(['error' => 'User is not a doctor'], 422);
        }

        $holidays = DoctorHoliday::where('user_id', $doctor->id)
            ->orderBy('date')
            ->paginate(10);
        return response()->json($holidays);
    }

    public function addHoliday(Request $request, User $doctor)
    {
        if (!in_array($doctor->role, ['doctor', 'sub-doctor'])) {
            return response()->json(['error' => 'User is not a doctor'], 422);
        }

        if ($request->user()->id !== $doctor->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $request->validate([
            'date' => 'nullable|date|required_without:recurring_day_of_week',
            'recurring_day_of_week' => 'nullable|integer|min:0|max:6|required_without:date',
            'reason' => 'nullable|string',
        ]);

        $recurringDayOfWeek = $request->input('recurring_day_of_week');
        $holidayDate = $request->date;

        if ($recurringDayOfWeek !== null) {
            $exists = DoctorHoliday::where('user_id', $doctor->id)
                ->where('recurring_day_of_week', (int) $recurringDayOfWeek)
                ->exists();

            if ($exists) {
                return response()->json([
                    'message' => 'Recurring holiday already exists for this day',
                    'errors' => [
                        'recurring_day_of_week' => ['Recurring holiday already exists for this day'],
                    ],
                ], 422);
            }

            $baseSunday = \Carbon\Carbon::create(2000, 1, 2);
            $holidayDate = $baseSunday->copy()->addDays((int) $recurringDayOfWeek)->toDateString();
        }

        $holiday = DoctorHoliday::create([
            'user_id' => $doctor->id,
            'date' => $holidayDate,
            'recurring_day_of_week' => $recurringDayOfWeek !== null ? (int) $recurringDayOfWeek : null,
            'reason' => $request->reason,
        ]);

        return response()->json($holiday, 201);
    }

    public function deleteHoliday(Request $request, User $doctor, DoctorHoliday $holiday)
    {
        if (!in_array($doctor->role, ['doctor', 'sub-doctor'])) {
            return response()->json(['error' => 'User is not a doctor'], 422);
        }

        if ($request->user()->id !== $doctor->id || $holiday->user_id !== $doctor->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $holiday->delete();
        return response()->json(['message' => 'Holiday deleted']);
    }

    /**
     * Get available time slots for a doctor on a specific date.
     * Returns 30-minute slots within the doctor's availability windows,
     * excluding already booked times and holidays.
     */
    public function getAvailableTimes(Request $request, User $doctor)
    {
        if (!in_array($doctor->role, ['doctor', 'sub-doctor'])) {
            return response()->json(['error' => 'User is not a doctor'], 422);
        }

        $request->validate([
            'date' => 'required|date',
        ]);

        $date = \Carbon\Carbon::parse($request->date);
        $dayOfWeek = (int) $date->dayOfWeek; // 0 (Sunday) to 6 (Saturday)

        // Check if doctor is on holiday
        $isHoliday = DoctorHoliday::where('user_id', $doctor->id)
            ->where(function ($query) use ($date, $dayOfWeek) {
                $query->whereDate('date', $date->toDateString())
                    ->orWhere('recurring_day_of_week', $dayOfWeek);
            })
            ->exists();

        if ($isHoliday) {
            return response()->json([
                'available' => false,
                'message' => 'Doctor is on holiday on this date',
                'slots' => [],
            ]);
        }

        // Get availability windows for this day of week
        $availabilities = DoctorAvailability::where('user_id', $doctor->id)
            ->where('day_of_week', $dayOfWeek)
            ->orderBy('start_time')
            ->get();

        if ($availabilities->isEmpty()) {
            return response()->json([
                'available' => false,
                'message' => 'Doctor has no availability on this day',
                'slots' => [],
            ]);
        }

        // Get existing reservations for this doctor on this date (exclude cancelled)
        $bookedTimes = \App\Models\Reservation::where('doctor_id', $doctor->id)
            ->whereDate('appointment_date', $date->toDateString())
            ->where('status', '!=', 'cancelled')
            ->pluck('appointment_date')
            ->map(function ($dt) {
                return \Carbon\Carbon::parse($dt)->format('H:i');
            })
            ->toArray();

        // Generate slots within each availability window using configured duration
        $slots = [];
        foreach ($availabilities as $avail) {
            $start = \Carbon\Carbon::parse($avail->start_time);
            $end = \Carbon\Carbon::parse($avail->end_time);
            $slotDuration = max(5, (int) ($avail->slot_duration_minutes ?? 30));

            while ($start->lt($end)) {
                $timeStr = $start->format('H:i');
                $slots[] = [
                    'time' => $timeStr,
                    'label' => $start->format('h:i A'),
                    'booked' => in_array($timeStr, $bookedTimes),
                ];
                $start->addMinutes($slotDuration);
            }
        }

        return response()->json([
            'available' => true,
            'slots' => $slots,
        ]);
    }
}
