<?php

namespace App\Http\Controllers;

use App\Events\ReservationCreated;
use App\Events\ReservationUpdated;
use App\Events\ReservationCompleted;
use App\Events\ReservationDeleted;
use App\Http\Traits\ResolvesDoctor;
use App\Models\Client;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Reservations\ReservationArchiveService;
use App\Services\Reservations\ReservationBookingService;
use App\Services\Reservations\ReservationLogService;
use App\Services\Reservations\ReservationPdfService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReservationController extends Controller
{
    use ResolvesDoctor;

    public function __construct(
        private ReservationBookingService $booking,
        private ReservationLogService $logs,
        private ReservationArchiveService $archives,
        private ReservationPdfService $pdfs,
    ) {
    }

    public function index(Request $request)
    {
        $doctorIds = $request->boolean('own_only')
            ? [$this->requireDoctorId($request)]
            : $this->getDoctorIds($request);

        $query = Reservation::with(['client' => $this->clientWithNoShowCount(), 'doctor', 'creator', 'archive.children'])
            ->whereIn('doctor_id', $doctorIds)
            ->where(function ($q) {
                $q->where('source', '!=', 'online')
                    ->orWhereNull('source');
            });

        // Optional filters
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        // Date range based on appointment_date
        if ($from = $request->query('date_from')) {
            $query->whereDate('appointment_date', '>=', $from);
        }
        if ($to = $request->query('date_to')) {
            $query->whereDate('appointment_date', '<=', $to);
        }
        // Search by client id/name/phone or notes. Typing "#123" targets client id 123.
        if ($search = $request->query('search')) {
            $clientIdSearch = $this->clientIdSearchTerm($search);

            if ($this->isStrictClientIdSearch($search)) {
                $query->where('client_id', $clientIdSearch ?? 0);
            } else {
                $query->where(function ($q) use ($search, $clientIdSearch) {
                    $q->whereHas('client', function ($qc) use ($search) {
                        $qc->where('name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%")
                            ->orWhere('whatsapp_number', 'like', "%{$search}%");
                    })->orWhere('notes', 'like', "%{$search}%");

                    if ($clientIdSearch !== null) {
                        $q->orWhere('client_id', $clientIdSearch);
                    }
                });
            }
        }

        $reservations = $query->latest()->paginate(10);
        return response()->json($reservations);
    }

    public function store(Request $request)
    {
        $callerDoctorIds = $this->getDoctorIds($request);
        $doctorId = (int) $request->doctor_id;

        // Ensure caller is authorized to book for this doctor / sub-doctor
        if (!in_array($doctorId, $callerDoctorIds)) {
            return response()->json(['error' => 'You are not authorized to book for this doctor'], 403);
        }

        $request->validate([
            'client_id' => 'required|exists:clients,id',
            'appointment_date' => 'required|string',
            'notes' => 'nullable|string',
            'amount' => 'required|numeric|min:0',
            'paid' => 'nullable|numeric|min:0',
            'payment_method' => 'required|in:cash,card,transfer,other',
            'source_reservation_id' => 'nullable|exists:reservations,id',
        ]);

        // Use the tenant doctor (doctor or sub-doctor)
        $doctor = User::where('id', $doctorId)->whereIn('role', ['doctor', 'sub-doctor'])->firstOrFail();

        // Verify client belongs to this doctor or, for sub-doctors, to their parent doctor
        if (!$this->booking->clientAllowedFor($doctor, (int) $request->client_id)) {
            return response()->json(['error' => 'Client does not belong to this doctor'], 422);
        }

        $appointment = Carbon::parse($request->appointment_date);

        // One transaction for the reservation, its log entries and its payment record, so a
        // failure can't leave a reservation without a financial record. The doctor row is
        // locked so two simultaneous bookings can't both take the same slot.
        $result = DB::transaction(function () use ($request, $doctor, $doctorId, $appointment, $callerDoctorIds) {
            User::whereKey($doctorId)->lockForUpdate()->first();

            // Schedule, holidays and overlapping bookings
            if ($error = $this->booking->slotError($doctor, $doctorId, $appointment)) {
                return ['error' => $error];
            }

            $reservation = Reservation::create([
                'client_id' => $request->client_id,
                'doctor_id' => $doctorId,
                'created_by' => $request->user()->id,
                'appointment_date' => $request->appointment_date,
                'notes' => $request->notes,
                'status' => 'pending',
            ]);

            $this->logs->log($reservation, $request->user(), 'created', 'Reservation created', [
                'appointment_date' => $reservation->appointment_date,
            ]);

            if ($request->filled('source_reservation_id')) {
                $sourceReservation = Reservation::query()
                    ->where('id', $request->source_reservation_id)
                    ->where('client_id', $request->client_id)
                    ->whereIn('doctor_id', $callerDoctorIds)
                    ->first();

                if ($sourceReservation) {
                    $this->logs->log($sourceReservation, $request->user(), 'future_reservation_created', 'Future reservation created', [
                        'new_reservation_id' => $reservation->id,
                        'appointment_date' => $reservation->appointment_date,
                    ]);
                }
            }

            // Financial record and optionally an initial transaction
            $this->booking->createInitialFinancial(
                $reservation,
                (int) $request->client_id,
                $doctorId,
                $request->user()->id,
                (float) $request->amount,
                (float) ($request->paid ?? 0),
                $request->payment_method,
            );

            return ['reservation' => $reservation];
        });

        if (isset($result['error'])) {
            return response()->json(['error' => $result['error']], 422);
        }

        $reservation = $result['reservation'];
        $reservation->load(['client', 'doctor', 'creator']);

        $reservationsBefore = $this->booking->reservationsBefore($reservation, $appointment);

        // Broadcast event for real-time updates (exclude the sender)
        broadcast(new ReservationCreated($reservation))->toOthers();

        $response = $reservation->toArray();
        $response['queue_position'] = $reservationsBefore + 1; // 1-based position
        $response['reservations_before'] = $reservationsBefore;

        return response()->json($response, 201);
    }

    public function show(Request $request, Reservation $reservation)
    {
        $doctorIds = $this->getDoctorIds($request);

        if (!in_array($reservation->doctor_id, $doctorIds)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $reservation->load(['client' => $this->clientWithNoShowCount(), 'doctor', 'creator', 'archive.children', 'logs.actor']);
        return response()->json($reservation);
    }

    public function update(Request $request, Reservation $reservation)
    {
        $doctorIds = $this->getDoctorIds($request);

        if (!in_array($reservation->doctor_id, $doctorIds)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'doctor_id' => 'required|exists:users,id',
            'appointment_date' => 'required|string',
            'status' => 'required|in:pending,confirmed,completed,cancelled,no_show',
            'notes' => 'nullable|string',
            'diagnosis' => 'nullable|string',
            'dental_chart' => 'nullable|array',
            'dental_chart.teeth' => 'nullable|array',
            'dental_chart.teeth.*.tooth' => 'required_with:dental_chart.teeth|integer|min:11|max:48',
            'dental_chart.teeth.*.problem' => 'required_with:dental_chart.teeth|string|in:pain,caries,gum,fracture,missing,treatment',
            'specialty_chart' => 'nullable|array',
            'specialty_chart.type' => 'nullable|string|max:50',
            'specialty_chart.items' => 'nullable|array',
            'specialty_chart.items.*.type' => 'nullable|string|max:50',
            'specialty_chart.items.*.part' => 'required_with:specialty_chart.items|string|max:80',
            'specialty_chart.items.*.problem' => 'required_with:specialty_chart.items|string|max:50',
            'treatment' => 'nullable|string',
            'requires_xray' => 'nullable|boolean',
            'xray_notes' => 'nullable|string',
            'requires_lab' => 'nullable|boolean',
            'lab_notes' => 'nullable|string',
        ]);

        $appointment = Carbon::parse($request->appointment_date);
        if ($this->booking->isPastDate($appointment)) {
            return response()->json(['error' => 'Reservations can only be scheduled for today or future dates'], 422);
        }

        // The reservation can only stay with (or move to) a doctor in the caller's clinic,
        // and can only be linked to a client of that doctor.
        $newDoctorId = (int) $validated['doctor_id'];
        if (!in_array($newDoctorId, $doctorIds)) {
            return response()->json(['error' => 'You are not authorized to book for this doctor'], 403);
        }

        $newDoctor = User::where('id', $newDoctorId)->whereIn('role', ['doctor', 'sub-doctor'])->first();
        if (!$newDoctor) {
            return response()->json(['error' => 'Selected doctor is not available'], 422);
        }

        if (!$this->booking->clientAllowedFor($newDoctor, (int) $validated['client_id'])) {
            return response()->json(['error' => 'Client does not belong to this doctor'], 422);
        }

        $newStatus = $request->status;
        $currentStatus = $reservation->status;

        // No-show has its own endpoint, which also checks the appointment time and check-in
        if ($newStatus === 'no_show' && $currentStatus !== 'no_show') {
            return response()->json(['error' => 'Use the no-show action to mark a reservation as no-show'], 422);
        }

        if ($error = $this->booking->transitionError($currentStatus, $newStatus)) {
            return response()->json(['error' => $error], 422);
        }

        // Rescheduling (new time or new doctor) must respect the schedule and not overlap another booking
        $rescheduled = $newDoctorId !== (int) $reservation->doctor_id
            || !$appointment->equalTo($reservation->appointment_date);

        DB::transaction(function () use ($reservation, $validated, $newDoctor, $newDoctorId, $appointment, $rescheduled, $newStatus, &$scheduleError) {
            User::whereKey($newDoctorId)->lockForUpdate()->first();

            if ($rescheduled && $newStatus !== 'cancelled') {
                $scheduleError = $this->booking->slotError($newDoctor, $newDoctorId, $appointment, $reservation->id);
                if ($scheduleError) {
                    return;
                }
            }

            // Only validated fields are saved (never the raw request), so protected columns
            // such as archive_id, created_by, checked_in_at or position can't be overwritten.
            $reservation->update($validated);
        });

        if (!empty($scheduleError)) {
            return response()->json(['error' => $scheduleError], 422);
        }

        $this->logs->log($reservation, $request->user(), $newStatus !== $currentStatus ? 'status_changed' : 'updated', 'Reservation updated', [
            'from_status' => $currentStatus,
            'to_status' => $newStatus,
        ]);
        $reservation->load(['client', 'doctor', 'creator', 'logs.actor']);

        // Broadcast event for real-time updates (exclude the sender)
        broadcast(new ReservationUpdated($reservation))->toOthers();

        return response()->json($reservation);
    }

    /**
     * Confirm a pending reservation (assistant only)
     */
    public function confirm(Request $request, Reservation $reservation)
    {
        if (!in_array($reservation->doctor_id, $this->getDoctorIds($request))) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if ($reservation->status !== 'pending') {
            return response()->json(['error' => 'Only pending reservations can be confirmed'], 422);
        }

        $reservation->update([
            'status' => 'confirmed',
            'source' => $reservation->source === 'online' ? 'internal' : $reservation->source,
        ]);
        $this->logs->log($reservation, $request->user(), 'confirmed', 'Reservation confirmed');
        $reservation->load(['client', 'doctor', 'creator', 'logs.actor']);

        broadcast(new ReservationUpdated($reservation))->toOthers();

        return response()->json($reservation);
    }

    /**
     * Mark a reservation as no-show: the patient did not arrive for the appointment.
     */
    public function markNoShow(Request $request, Reservation $reservation)
    {
        if (!in_array($reservation->doctor_id, $this->getDoctorIds($request))) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if (!in_array($reservation->status, ['pending', 'confirmed'])) {
            return response()->json(['error' => 'Only pending or confirmed reservations can be marked as no-show'], 422);
        }

        if ($reservation->checked_in_at) {
            return response()->json(['error' => 'The patient is checked in. Undo the check-in first'], 422);
        }

        if (Carbon::parse($reservation->appointment_date)->isFuture()) {
            return response()->json(['error' => 'A future reservation cannot be marked as no-show'], 422);
        }

        $reservation->update(['status' => 'no_show']);
        $this->logs->log($reservation, $request->user(), 'no_show', 'Patient did not show up');
        $reservation->load(['client' => $this->clientWithNoShowCount(), 'doctor', 'creator', 'logs.actor']);

        broadcast(new ReservationUpdated($reservation))->toOthers();

        return response()->json($reservation);
    }

    /** Eager-load constraint: adds `no_show_count` (missed appointments) to the client. */
    private function clientWithNoShowCount(): \Closure
    {
        return fn ($query) => $query->withCount([
            'reservations as no_show_count' => fn ($r) => $r->where('status', 'no_show'),
        ]);
    }

    public function complete(Request $request, Reservation $reservation)
    {
        $user = $request->user();

        // Doctors, assistants and sub-doctors can complete reservations.
        if (!in_array($user->role, ['doctor', 'assistant', 'sub-doctor'])) {
            return response()->json(['error' => 'You are not allowed to complete reservations'], 403);
        }

        $doctorIds = $this->getDoctorIds($request);

        // Only reservations in the user's doctor scope can be completed.
        if (!in_array($reservation->doctor_id, $doctorIds)) {
            return response()->json(['error' => 'You can only complete reservations in your clinic scope'], 403);
        }

        if (is_string($request->input('dental_chart'))) {
            $decodedDentalChart = json_decode($request->input('dental_chart'), true);
            $request->merge([
                'dental_chart' => json_last_error() === JSON_ERROR_NONE ? $decodedDentalChart : null,
            ]);
        }

        if (is_string($request->input('specialty_chart'))) {
            $decodedSpecialtyChart = json_decode($request->input('specialty_chart'), true);
            $request->merge([
                'specialty_chart' => json_last_error() === JSON_ERROR_NONE ? $decodedSpecialtyChart : null,
            ]);
        }

        $request->validate([
            'diagnosis' => 'nullable|string',
            'treatment' => 'nullable|string',
            'current_procedures' => 'nullable|string',
            'procedure_notes' => 'nullable|string',
            'next_procedures' => 'nullable|string',
            'files' => 'nullable|array',
            'files.*' => 'file|max:10240',
            'requires_xray' => 'nullable|boolean',
            'xray_notes' => 'nullable|string',
            'requires_lab' => 'nullable|boolean',
            'lab_notes' => 'nullable|string',
        ]);

        $reservation->update([
            'status' => 'completed',
            'diagnosis' => $request->diagnosis,
            'dental_chart' => $request->input('dental_chart'),
            'specialty_chart' => $request->input('specialty_chart'),
            'treatment' => $request->treatment,
            'current_procedures' => $request->current_procedures,
            'procedure_notes' => $request->procedure_notes,
            'next_procedures' => $request->next_procedures,
            'requires_xray' => $request->boolean('requires_xray'),
            'xray_notes' => $request->requires_xray ? $request->xray_notes : null,
            'requires_lab' => $request->boolean('requires_lab'),
            'lab_notes' => $request->requires_lab ? $request->lab_notes : null,
            'completed_at' => now(),
        ]);

        $this->logs->log($reservation, $request->user(), 'completed', 'Reservation completed');

        if ($request->hasFile('files')) {
            $this->archives->storeCompletionFiles($reservation, $request->file('files'));
        }

        $reservation->load(['client', 'doctor', 'creator', 'archive.children', 'logs.actor']);

        // Broadcast event for real-time updates (exclude the sender)
        broadcast(new ReservationCompleted($reservation))->toOthers();

        return response()->json($reservation);
    }

    public function destroy(Request $request, Reservation $reservation)
    {
        $doctorIds = $this->getDoctorIds($request);

        if (!in_array($reservation->doctor_id, $doctorIds)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $reservationId = $reservation->id;
        $doctorId = $reservation->doctor_id;
        $clientName = $reservation->client->name ?? 'Unknown';

        $reservation->delete();

        // Broadcast event for real-time updates (exclude the sender)
        broadcast(new ReservationDeleted($reservationId, $doctorId, $clientName))->toOthers();

        return response()->json(['message' => 'Reservation deleted successfully']);
    }

    public function doctors(Request $request)
    {
        $role = $request->user()->role;

        // If assistant, return their assigned doctor + that doctor's sub-doctors
        if ($role === 'assistant') {
            $doctorId = $request->user()->doctor_id;
            $doctors = User::where(function ($q) use ($doctorId) {
                $q->where('id', $doctorId)
                  ->orWhere(function ($q2) use ($doctorId) {
                      $q2->where('role', 'sub-doctor')
                         ->where('parent_doctor_id', $doctorId);
                  });
            })->get(['id', 'name', 'email', 'role']);
            return response()->json($doctors);
        }

        // If sub-doctor, return only their parent doctor
        if ($role === 'sub-doctor') {
            $doctorId = $request->user()->parent_doctor_id;
            $doctors = User::where('id', $doctorId)->where('role', 'doctor')->get(['id', 'name', 'email']);
            return response()->json($doctors);
        }

        // If doctor, return only themselves
        if ($role === 'doctor') {
            $doctors = User::where('id', $request->user()->id)->get(['id', 'name', 'email']);
            return response()->json($doctors);
        }

        $doctors = User::where('role', 'doctor')->get(['id', 'name', 'email']);
        return response()->json($doctors);
    }

    public function generatePrescription(Request $request, Reservation $reservation)
    {
        // Only the assigned doctor (or their assistant) can generate prescription
        if (!in_array($reservation->doctor_id, $this->getDoctorIds($request))) {
            return response()->json(['error' => 'You can only generate prescriptions for your own reservations'], 403);
        }

        // Only completed reservations can have prescriptions
        if ($reservation->status !== 'completed') {
            return response()->json(['error' => 'Only completed reservations can have prescriptions generated'], 400);
        }

        $reservation->load(['client', 'doctor', 'creator']);

        return $this->pdfs->prescription($reservation, $this->wantsArabic($request));
    }

    public function generateReservationDetailsPdf(Request $request, Reservation $reservation)
    {
        if (!in_array($reservation->doctor_id, $this->getDoctorIds($request))) {
            return response()->json(['error' => 'You can only generate details for your own reservations'], 403);
        }

        $reservation->load(['client', 'doctor', 'creator']);

        return $this->pdfs->reservationDetails($reservation, $this->wantsArabic($request));
    }

    public function generateMedicinesPrescription(Request $request, Reservation $reservation)
    {
        if (!in_array($reservation->doctor_id, $this->getDoctorIds($request))) {
            return response()->json(['error' => 'You can only generate prescriptions for your own reservations'], 403);
        }

        if ($reservation->status !== 'completed') {
            return response()->json(['error' => 'Only completed reservations can have prescriptions generated'], 400);
        }

        $reservation->load(['client', 'doctor']);

        return $this->pdfs->medicinesPrescription($reservation, $this->wantsArabic($request));
    }

    private function wantsArabic(Request $request): bool
    {
        $lang = strtolower((string) ($request->query('lang') ?: $request->header('Accept-Language', 'en')));

        return str_starts_with($lang, 'ar');
    }

    private function clientIdSearchTerm(string $search): ?int
    {
        $term = trim($search);

        if (!preg_match('/^#?\d+$/', $term)) {
            return null;
        }

        return (int) ltrim($term, '#');
    }

    private function isStrictClientIdSearch(string $search): bool
    {
        return str_starts_with(trim($search), '#');
    }
}
