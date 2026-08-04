<?php

namespace App\Http\Controllers;

use App\Events\ReservationCreated;
use App\Events\ReservationUpdated;
use App\Events\ReservationCompleted;
use App\Events\ReservationDeleted;
use App\Http\Traits\ResolvesDoctor;
use App\Models\Financial;
use App\Models\Transaction;
use App\Models\Archive;
use App\Models\Reservation;
use App\Models\ReservationLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use TCPDF;

class ReservationController extends Controller
{
    use ResolvesDoctor;

    private function logReservationAction(Reservation $reservation, Request $request, string $action, ?string $description = null, array $meta = []): void
    {
        ReservationLog::create([
            'reservation_id' => $reservation->id,
            'user_id' => $request->user()?->id,
            'action' => $action,
            'description' => $description,
            'meta' => $meta ?: null,
        ]);
    }

    private function printSettingsFor(Reservation $reservation, array $labels): array
    {
        $doctor = $reservation->doctor;

        return [
            'clinic_name' => $doctor?->print_clinic_name ?: $labels['clinic'],
            'clinic_phone' => $doctor?->print_clinic_phone,
            'clinic_address' => $doctor?->print_clinic_address,
            'header_text' => $doctor?->print_header_text,
            'footer_text' => $doctor?->print_footer_text,
            'color' => $this->hexColorToRgb($doctor?->print_primary_color ?: '#2C5AA0'),
            'clinic_name_position' => $doctor?->print_clinic_name_position ?: 'center',
            'patient_info_position' => $doctor?->print_patient_info_position ?: 'top',
        ];
    }

    private function hexColorToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        if (!preg_match('/^[0-9A-Fa-f]{6}$/', $hex)) {
            return [44, 90, 160];
        }

        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }

    private function drawPrintHeader(TCPDF $pdf, array $settings, string $title): void
    {
        [$red, $green, $blue] = $settings['color'];
        $headerAlign = $this->pdfAlign($settings['clinic_name_position'] ?? 'center');

        $pdf->SetFillColor($red, $green, $blue);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('dejavusans', 'B', 16);
        $pdf->Cell(0, 12, $settings['clinic_name'], 0, 1, $headerAlign, 1);
        $pdf->SetTextColor(0, 0, 0);

        $infoLines = array_filter([
            $settings['clinic_phone'],
            $settings['clinic_address'],
            $settings['header_text'],
        ]);

        if ($infoLines) {
            $pdf->Ln(2);
            $pdf->SetFont('dejavusans', '', 9);
            foreach ($infoLines as $line) {
                $pdf->MultiCell(0, 5, $line, 0, $headerAlign);
            }
        }

        $pdf->Ln(4);
        $pdf->SetFont('dejavusans', 'B', 16);
        $pdf->Cell(0, 10, $title, 0, 1, 'C');
        $pdf->Ln(4);
    }

    private function pdfAlign(string $position): string
    {
        return match ($position) {
            'left' => 'L',
            'right' => 'R',
            default => 'C',
        };
    }

    private function shouldDrawPatientInfo(array $settings, string $position): bool
    {
        return ($settings['patient_info_position'] ?? 'top') === $position;
    }

    private function drawPatientInfo(
        TCPDF $pdf,
        Reservation $reservation,
        array $labels,
        string $titleKey,
        string $align,
        array $options = []
    ): void {
        $pdf->SetFont('dejavusans', 'B', 12);
        $pdf->Cell(0, 8, $labels[$titleKey], 0, 1, $align, 1);
        $pdf->SetFont('dejavusans', '', 10);
        $this->pdfLabelValue($pdf, $labels['name'], $reservation->client->name, $align);
        $this->pdfLabelValue($pdf, $labels['email'], $reservation->client->email ?: $labels['na'], $align);
        $this->pdfLabelValue($pdf, $labels['phone'], $reservation->client->phone ?: $labels['na'], $align);
        $this->pdfLabelValue($pdf, $labels['whatsapp'], $reservation->client->whatsapp_number ?: $labels['na'], $align);

        if (($options['dob'] ?? false) && $reservation->client->date_of_birth) {
            $this->pdfLabelValue($pdf, $labels['dob'], \Carbon\Carbon::parse($reservation->client->date_of_birth)->format('M d, Y'), $align);
        }

        if (($options['address'] ?? false) && $reservation->client->address) {
            $this->pdfLabelValue($pdf, $labels['address'], $reservation->client->address, $align);
        }

        if (($options['job'] ?? false) && $reservation->client->job) {
            $this->pdfLabelValue($pdf, $labels['job'], $reservation->client->job, $align);
        }

        if (($options['chronic'] ?? false) && $reservation->client->chronic_illnesses) {
            $pdf->Cell(50, 6, $labels['chronicIllnesses'] . ':', 0, 0, $align);
            $pdf->MultiCell(0, 6, $this->formatChronicIllnesses($reservation->client->chronic_illnesses, $options['isArabic'] ?? false), 0, $align);
        }

        $pdf->Ln(4);
    }

    private function drawPrintFooter(TCPDF $pdf, array $settings, string $fallbackText = ''): void
    {
        $footer = $settings['footer_text'] ?: $fallbackText;
        if (!$footer) {
            return;
        }

        $pdf->Ln(5);
        $pdf->SetFont('dejavusans', '', 8);
        $pdf->MultiCell(0, 6, $footer, 0, 'C');
    }

    public function index(Request $request)
    {
        $doctorIds = $request->boolean('own_only')
            ? [$this->requireDoctorId($request)]
            : $this->getDoctorIds($request);

        $query = Reservation::with(['client', 'doctor', 'creator', 'archive.children'])
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
        $allowedDoctorIds = [$doctorId];
        if ($doctor->role === 'sub-doctor' && $doctor->parent_doctor_id) {
            $allowedDoctorIds[] = $doctor->parent_doctor_id;
        }
        $client = \App\Models\Client::where('id', $request->client_id)
            ->whereIn('doctor_id', $allowedDoctorIds)
            ->first();
        if (!$client) {
            return response()->json(['error' => 'Client does not belong to this doctor'], 422);
        }

        // Validate appointment against doctor's schedule and holidays
        $appointment = \Carbon\Carbon::parse($request->appointment_date);
        if ($appointment->toDateString() < \Carbon\Carbon::today()->toDateString()) {
            return response()->json(['error' => 'Reservations can only be created for today or future dates'], 422);
        }
        $dayOfWeek = (int) $appointment->dayOfWeek; // 0 (Sunday) to 6 (Saturday)

        // Check holidays (specific date or recurring weekday)
        $isHoliday = \App\Models\DoctorHoliday::where('user_id', $doctor->id)
            ->where(function ($query) use ($appointment, $dayOfWeek) {
                $query->whereDate('date', $appointment->toDateString())
                    ->orWhere('recurring_day_of_week', $dayOfWeek);
            })
            ->exists();
        if ($isHoliday) {
            return response()->json(['error' => 'Doctor is on holiday on selected date'], 422);
        }

        // Check availability window for the day
        $timeStr = $appointment->format('H:i');
        $hasAvailability = \App\Models\DoctorAvailability::where('user_id', $doctor->id)
            ->where('day_of_week', $dayOfWeek)
            ->where('start_time', '<=', $timeStr)
            ->where('end_time', '>=', $timeStr)
            ->exists();
        if (!$hasAvailability) {
            return response()->json(['error' => 'Selected time is outside doctor availability'], 422);
        }

        // Prevent double booking at exact same timestamp (across the whole schedule owner's pool)
        $conflict = Reservation::where('doctor_id', $doctorId)
            ->where('appointment_date', $appointment)
            ->exists();
        if ($conflict) {
            return response()->json(['error' => 'Selected time is already booked'], 422);
        }

        $reservation = Reservation::create([
            'client_id' => $request->client_id,
            'doctor_id' => $doctorId,
            'created_by' => $request->user()->id,
            'appointment_date' => $request->appointment_date,
            'notes' => $request->notes,
            'status' => 'pending',
        ]);

        $this->logReservationAction($reservation, $request, 'created', 'Reservation created', [
            'appointment_date' => $reservation->appointment_date,
        ]);

        if ($request->filled('source_reservation_id')) {
            $sourceReservation = Reservation::query()
                ->where('id', $request->source_reservation_id)
                ->where('client_id', $request->client_id)
                ->whereIn('doctor_id', $callerDoctorIds)
                ->first();

            if ($sourceReservation) {
                $this->logReservationAction($sourceReservation, $request, 'future_reservation_created', 'Future reservation created', [
                    'new_reservation_id' => $reservation->id,
                    'appointment_date' => $reservation->appointment_date,
                ]);
            }
        }

        $reservation->load(['client', 'doctor', 'creator']);

        // Create financial record and optionally an initial transaction
        $amount = $request->amount;
        $paid = $request->paid ?? 0;
        $remaining = $amount - $paid;
        $paymentStatus = $paid <= 0 ? 'unpaid' : ($paid >= $amount ? 'paid' : 'partial');

        DB::transaction(function () use ($request, $reservation, $doctorId, $amount, $paid, $remaining, $paymentStatus) {
            $financial = Financial::create([
                'reservation_id' => $reservation->id,
                'client_id'      => $request->client_id,
                'doctor_id'      => $doctorId,
                'created_by'     => $request->user()->id,
                'amount'         => $amount,
                'paid'           => $paid,
                'remaining'      => $remaining,
                'payment_status' => $paymentStatus,
                'payment_method' => $request->payment_method,
                'notes'          => null,
            ]);

            if ($paid > 0) {
                Transaction::create([
                    'financial_id'   => $financial->id,
                    'doctor_id'      => $doctorId,
                    'created_by'     => $request->user()->id,
                    'amount'         => $paid,
                    'payment_method' => $request->payment_method,
                    'notes'          => null,
                ]);
            }
        });

        // Count how many reservations are before this one on the same day for the same doctor
        $queuePosition = Reservation::where('doctor_id', $reservation->doctor_id)
            ->whereDate('appointment_date', $appointment->toDateString())
            ->whereIn('status', ['pending', 'confirmed'])
            ->where('appointment_date', '<', $reservation->appointment_date)
            ->count();

        // Broadcast event for real-time updates (exclude the sender)
        broadcast(new ReservationCreated($reservation))->toOthers();

        $response = $reservation->toArray();
        $response['queue_position'] = $queuePosition + 1; // 1-based position
        $response['reservations_before'] = $queuePosition;

        return response()->json($response, 201);
    }

    public function show(Request $request, Reservation $reservation)
    {
        $doctorIds = $this->getDoctorIds($request);

        if (!in_array($reservation->doctor_id, $doctorIds)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $reservation->load(['client', 'doctor', 'creator', 'archive.children', 'logs.actor']);
        return response()->json($reservation);
    }

    /**
     * Valid status transitions:
     *   pending   → confirmed, cancelled
     *   confirmed → completed, cancelled
     *   completed → (terminal)
     *   cancelled → (terminal)
     */
    private const STATUS_TRANSITIONS = [
        'pending'   => ['confirmed', 'cancelled'],
        'confirmed' => ['completed', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
    ];

    public function update(Request $request, Reservation $reservation)
    {
        $doctorIds = $this->getDoctorIds($request);

        if (!in_array($reservation->doctor_id, $doctorIds)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $request->validate([
            'client_id' => 'required|exists:clients,id',
            'doctor_id' => 'required|exists:users,id',
            'appointment_date' => 'required|string',
            'status' => 'required|in:pending,confirmed,completed,cancelled',
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

        $appointment = \Carbon\Carbon::parse($request->appointment_date);
        if ($appointment->toDateString() < \Carbon\Carbon::today()->toDateString()) {
            return response()->json(['error' => 'Reservations can only be scheduled for today or future dates'], 422);
        }

        $newStatus = $request->status;
        $currentStatus = $reservation->status;

        // Validate status transition
        if ($newStatus !== $currentStatus) {
            $allowed = self::STATUS_TRANSITIONS[$currentStatus] ?? [];
            if (!in_array($newStatus, $allowed)) {
                return response()->json([
                    'error' => "Cannot change status from {$currentStatus} to {$newStatus}",
                ], 422);
            }
        }

        $reservation->update($request->all());
        $this->logReservationAction($reservation, $request, $newStatus !== $currentStatus ? 'status_changed' : 'updated', 'Reservation updated', [
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
        if ($reservation->status !== 'pending') {
            return response()->json(['error' => 'Only pending reservations can be confirmed'], 422);
        }

        $reservation->update([
            'status' => 'confirmed',
            'source' => $reservation->source === 'online' ? 'internal' : $reservation->source,
        ]);
        $this->logReservationAction($reservation, $request, 'confirmed', 'Reservation confirmed');
        $reservation->load(['client', 'doctor', 'creator', 'logs.actor']);

        broadcast(new ReservationUpdated($reservation))->toOthers();

        return response()->json($reservation);
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

        $this->logReservationAction($reservation, $request, 'completed', 'Reservation completed');

        if ($request->hasFile('files')) {
            $archiveFolder = $this->ensureReservationArchiveFolder($reservation);

            foreach ($request->file('files') as $file) {
                Archive::createFile($archiveFolder, $file, [
                    'content_type' => 'reservation_completion_file',
                    'language' => app()->getLocale(),
                ]);
            }
        }

        $reservation->load(['client', 'doctor', 'creator', 'archive.children', 'logs.actor']);

        // Broadcast event for real-time updates (exclude the sender)
        broadcast(new ReservationCompleted($reservation))->toOthers();

        return response()->json($reservation);
    }

    private function ensureReservationArchiveFolder(Reservation $reservation): Archive
    {
        if ($reservation->archive_id) {
            $archive = Archive::with('children')->find($reservation->archive_id);
            if ($archive) {
                return $archive;
            }
        }

        $rootFolder = Archive::query()
            ->where('parent_id', 0)
            ->where('type', Archive::TYPE_FOLDER)
            ->where('title', 'reservations')
            ->first();

        if (!$rootFolder) {
            $rootFolder = Archive::createFolder(Archive::root(), [
                'title' => 'reservations',
                'short_name' => 'reservations',
                'content_type' => 'reservations',
                'language' => app()->getLocale(),
            ]);
        }

        $folderTitle = 'reservation-' . $reservation->id;

        $reservationFolder = Archive::query()
            ->where('parent_id', $rootFolder->id)
            ->where('type', Archive::TYPE_FOLDER)
            ->where('title', $folderTitle)
            ->first();

        if (!$reservationFolder) {
            $reservationFolder = Archive::createFolder($rootFolder, [
                'title' => $folderTitle,
                'short_name' => $folderTitle,
                'content_type' => 'reservation_files',
                'language' => app()->getLocale(),
            ]);
        }

        if ($reservation->archive_id !== $reservationFolder->id) {
            $reservation->archive_id = $reservationFolder->id;
            $reservation->save();
        }

        return $reservationFolder->load('children');
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
        $doctorIds = $this->getDoctorIds($request);

        // Only the assigned doctor (or their assistant) can generate prescription
        if (!in_array($reservation->doctor_id, $doctorIds)) {
            return response()->json(['error' => 'You can only generate prescriptions for your own reservations'], 403);
        }

        // Only completed reservations can have prescriptions
        if ($reservation->status !== 'completed') {
            return response()->json(['error' => 'Only completed reservations can have prescriptions generated'], 400);
        }

        // Load relationships
        $reservation->load(['client', 'doctor', 'creator']);

        $lang = strtolower((string) ($request->query('lang') ?: $request->header('Accept-Language', 'en')));
        $isArabic = str_starts_with($lang, 'ar');

        $labels = $isArabic
            ? [
                'clinic' => 'العيادة الطبية',
                'title' => 'الوصفة الطبية',
                'patientInfo' => 'معلومات المريض',
                'name' => 'الاسم',
                'email' => 'البريد الإلكتروني',
                'phone' => 'الهاتف',
                'whatsapp' => 'واتساب',
                'dob' => 'تاريخ الميلاد',
                'address' => 'العنوان',
                'job' => 'الوظيفة',
                'chronicIllnesses' => 'الأمراض المزمنة',
                'doctorInfo' => 'معلومات الطبيب',
                'doctor' => 'الطبيب',
                'date' => 'التاريخ',
                'diagnosis' => 'التشخيص',
                'treatment' => 'خطة العلاج',
                'currentProcedures' => 'الإجراءات الحالية',
                'procedureNotes' => 'ملاحظات الإجراءات',
                'nextProcedures' => 'الإجراءات القادمة',
                'medicalHistory' => 'التاريخ المرضي',
                'signature' => 'التوقيع الرقمي',
                'notes' => 'هذه وصفة طبية مولدة رقمياً. يرجى استشارة طبيبك لأي أسئلة أو مخاوف.',
                'noDiagnosis' => 'لم يتم تقديم تشخيص.',
                'noTreatment' => 'لم يتم تقديم خطة علاج.',
                'noCurrentProcedures' => 'لم يتم تسجيل إجراءات حالية.',
                'noProcedureNotes' => 'لا توجد ملاحظات إجراءات.',
                'noNextProcedures' => 'لم يتم تسجيل إجراءات قادمة.',
                'noChronicIllnesses' => 'لا توجد أمراض مزمنة مسجلة.',
                'xrayRequired' => 'يتطلب أشعة سينية',
                'labRequired' => 'يتطلب تحاليل مختبرية',
                'xrayNotes' => 'ملاحظات الأشعة',
                'labNotes' => 'ملاحظات التحاليل',
                'na' => 'غير متوفر',
            ]
            : [
                'clinic' => 'Medical Clinic',
                'title' => 'Medical Prescription',
                'patientInfo' => 'Patient Information',
                'name' => 'Name',
                'email' => 'Email',
                'phone' => 'Phone',
                'whatsapp' => 'WhatsApp',
                'dob' => 'DOB',
                'address' => 'Address',
                'job' => 'Job',
                'chronicIllnesses' => 'Chronic diseases',
                'doctorInfo' => 'Doctor Information',
                'doctor' => 'Doctor',
                'date' => 'Date',
                'diagnosis' => 'Diagnosis',
                'treatment' => 'Treatment Plan',
                'currentProcedures' => 'Current Procedures',
                'procedureNotes' => 'Procedure Notes',
                'nextProcedures' => 'Next Procedures',
                'medicalHistory' => 'Medical History',
                'signature' => 'Digital Signature',
                'notes' => 'This is a digitally generated prescription. Please consult your doctor for any questions or concerns.',
                'noDiagnosis' => 'No diagnosis provided.',
                'noTreatment' => 'No treatment plan provided.',
                'noCurrentProcedures' => 'No current procedures recorded.',
                'noProcedureNotes' => 'No procedure notes provided.',
                'noNextProcedures' => 'No next procedures recorded.',
                'noChronicIllnesses' => 'No illnesses recorded.',
                'xrayRequired' => 'Requires X-Ray',
                'labRequired' => 'Requires Lab Tests',
                'xrayNotes' => 'X-Ray Notes',
                'labNotes' => 'Lab Notes',
                'na' => 'N/A',
            ];

        $align = $isArabic ? 'R' : 'L';
        $printSettings = $this->printSettingsFor($reservation, $labels);

        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Medical Clinic System');
        $pdf->SetAuthor('Dr. ' . $reservation->doctor->name);
        $pdf->SetTitle($labels['title']);
        $pdf->SetSubject($labels['title'] . ' - ' . $reservation->client->name);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->setRTL($isArabic);
        $pdf->SetMargins(15, 20, 15);
        $pdf->SetAutoPageBreak(true, 25);
        $pdf->AddPage();
        $pdf->SetFont('dejavusans', '', 12);

        $this->drawPrintHeader($pdf, $printSettings, $labels['title']);

        $patientOptions = [
            'dob' => true,
            'address' => true,
            'job' => true,
            'chronic' => true,
            'isArabic' => $isArabic,
        ];

        if ($this->shouldDrawPatientInfo($printSettings, 'top')) {
            $this->drawPatientInfo($pdf, $reservation, $labels, 'patientInfo', $align, $patientOptions);
        }

        $pdf->SetFont('dejavusans', 'B', 12);
        $pdf->Cell(0, 8, $labels['doctorInfo'], 0, 1, $align, 1);
        $pdf->SetFont('dejavusans', '', 10);
        $pdf->Cell(50, 6, $labels['doctor'] . ':', 0, 0, $align);
        $pdf->Cell(0, 6, 'Dr. ' . $reservation->doctor->name, 0, 1, $align);
        $pdf->Cell(50, 6, $labels['date'] . ':', 0, 0, $align);
        $pdf->Cell(0, 6, \Carbon\Carbon::parse($reservation->appointment_date)->format('M d, Y H:i'), 0, 1, $align);
        $pdf->Ln(4);

        if ($this->shouldDrawPatientInfo($printSettings, 'after_doctor')) {
            $this->drawPatientInfo($pdf, $reservation, $labels, 'patientInfo', $align, $patientOptions);
        }

        $pdf->Ln(4);
        $pdf->SetFont('dejavusans', 'B', 12);
        $pdf->Cell(0, 8, $labels['diagnosis'], 0, 1, $align, 1);
        $pdf->SetFont('dejavusans', '', 10);
        $pdf->MultiCell(0, 6, $reservation->diagnosis ?: $labels['noDiagnosis'], 1, $align);

        $pdf->Ln(4);
        $pdf->SetFont('dejavusans', 'B', 12);
        $pdf->Cell(0, 8, $labels['treatment'], 0, 1, $align, 1);
        $pdf->SetFont('dejavusans', '', 10);
        $pdf->MultiCell(0, 6, $reservation->treatment ?: $labels['noTreatment'], 1, $align);

        $pdf->Ln(4);
        $pdf->SetFont('dejavusans', 'B', 12);
        $pdf->Cell(0, 8, $labels['currentProcedures'], 0, 1, $align, 1);
        $pdf->SetFont('dejavusans', '', 10);
        $pdf->MultiCell(0, 6, $reservation->current_procedures ?: $labels['noCurrentProcedures'], 1, $align);

        $pdf->Ln(4);
        $pdf->SetFont('dejavusans', 'B', 12);
        $pdf->Cell(0, 8, $labels['procedureNotes'], 0, 1, $align, 1);
        $pdf->SetFont('dejavusans', '', 10);
        $pdf->MultiCell(0, 6, $reservation->procedure_notes ?: $labels['noProcedureNotes'], 1, $align);

        $pdf->Ln(4);
        $pdf->SetFont('dejavusans', 'B', 12);
        $pdf->Cell(0, 8, $labels['nextProcedures'], 0, 1, $align, 1);
        $pdf->SetFont('dejavusans', '', 10);
        $pdf->MultiCell(0, 6, $reservation->next_procedures ?: $labels['noNextProcedures'], 1, $align);

        if ($reservation->requires_xray || $reservation->requires_lab) {
            $pdf->Ln(4);
            $pdf->SetFont('dejavusans', 'B', 12);
            $pdf->Cell(0, 8, $isArabic ? 'متطلبات إضافية' : 'Additional Requirements', 0, 1, $align, 1);
            $pdf->SetFont('dejavusans', '', 10);
            if ($reservation->requires_xray) {
                $pdf->Cell(0, 6, '- ' . $labels['xrayRequired'], 0, 1, $align);
                if ($reservation->xray_notes) {
                    $pdf->MultiCell(0, 6, $labels['xrayNotes'] . ': ' . $reservation->xray_notes, 0, $align);
                }
            }
            if ($reservation->requires_lab) {
                $pdf->Cell(0, 6, '- ' . $labels['labRequired'], 0, 1, $align);
                if ($reservation->lab_notes) {
                    $pdf->MultiCell(0, 6, $labels['labNotes'] . ': ' . $reservation->lab_notes, 0, $align);
                }
            }
        }

        if ($reservation->client->medical_history) {
            $pdf->Ln(4);
            $pdf->SetFont('dejavusans', 'B', 12);
            $pdf->Cell(0, 8, $labels['medicalHistory'], 0, 1, $align, 1);
            $pdf->SetFont('dejavusans', '', 10);
            $pdf->MultiCell(0, 6, $reservation->client->medical_history, 1, $align);
        }

        if ($this->shouldDrawPatientInfo($printSettings, 'bottom')) {
            $pdf->Ln(4);
            $this->drawPatientInfo($pdf, $reservation, $labels, 'patientInfo', $align, $patientOptions);
        }

        $pdf->Ln(12);
        $pdf->SetFont('dejavusans', '', 10);
        $pdf->Cell(0, 6, $labels['signature'] . ': Dr. ' . $reservation->doctor->name, 0, 1, $align);
        $pdf->Cell(0, 6, str_repeat('_', 40), 0, 1, $align);
        $this->drawPrintFooter($pdf, $printSettings, $labels['notes']);

        $filePrefix = $isArabic ? 'prescription-ar' : 'prescription-en';
        $fileName = $filePrefix . '_' . $reservation->id . '_' . date('Y-m-d') . '.pdf';
        
        return response($pdf->Output($fileName, 'S'))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="' . $fileName . '"');
    }

    public function generateReservationDetailsPdf(Request $request, Reservation $reservation)
    {
        $doctorIds = $this->getDoctorIds($request);

        if (!in_array($reservation->doctor_id, $doctorIds)) {
            return response()->json(['error' => 'You can only generate details for your own reservations'], 403);
        }

        $reservation->load(['client', 'doctor', 'creator']);

        $lang = strtolower((string) ($request->query('lang') ?: $request->header('Accept-Language', 'en')));
        $isArabic = str_starts_with($lang, 'ar');

        $labels = $isArabic
            ? [
                'clinic' => 'العيادة الطبية',
                'title' => 'تفاصيل الحجز',
                'clientInfo' => 'بيانات العميل',
                'reservationInfo' => 'بيانات الحجز',
                'name' => 'الاسم',
                'email' => 'البريد الإلكتروني',
                'phone' => 'الهاتف',
                'whatsapp' => 'واتساب',
                'doctor' => 'الطبيب',
                'status' => 'الحالة',
                'appointment' => 'الموعد',
                'created' => 'تاريخ الإنشاء',
                'completedAt' => 'تاريخ الإكمال',
                'notes' => 'الملاحظات',
                'diagnosis' => 'التشخيص',
                'treatment' => 'العلاج',
                'currentProcedures' => 'الإجراءات الحالية',
                'procedureNotes' => 'ملاحظات الإجراءات',
                'nextProcedures' => 'الإجراءات القادمة',
                'requirements' => 'متطلبات إضافية',
                'xrayRequired' => 'يتطلب أشعة سينية',
                'labRequired' => 'يتطلب تحاليل مخبرية',
                'xrayNotes' => 'ملاحظات الأشعة',
                'labNotes' => 'ملاحظات التحاليل',
                'na' => 'غير متوفر',
            ]
            : [
                'clinic' => 'Medical Clinic',
                'title' => 'Reservation Details',
                'clientInfo' => 'Client Information',
                'reservationInfo' => 'Reservation Information',
                'name' => 'Name',
                'email' => 'Email',
                'phone' => 'Phone',
                'whatsapp' => 'WhatsApp',
                'doctor' => 'Doctor',
                'status' => 'Status',
                'appointment' => 'Appointment',
                'created' => 'Created',
                'completedAt' => 'Completed At',
                'notes' => 'Notes',
                'diagnosis' => 'Diagnosis',
                'treatment' => 'Treatment',
                'currentProcedures' => 'Current Procedures',
                'procedureNotes' => 'Procedure Notes',
                'nextProcedures' => 'Next Procedures',
                'requirements' => 'Additional Requirements',
                'xrayRequired' => 'Requires X-Ray',
                'labRequired' => 'Requires Lab Tests',
                'xrayNotes' => 'X-Ray Notes',
                'labNotes' => 'Lab Notes',
                'na' => 'N/A',
            ];

        $align = $isArabic ? 'R' : 'L';
        $printSettings = $this->printSettingsFor($reservation, $labels);

        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Medical Clinic System');
        $pdf->SetAuthor('Dr. ' . $reservation->doctor->name);
        $pdf->SetTitle($labels['title']);
        $pdf->SetSubject($labels['title'] . ' - ' . $reservation->client->name);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->setRTL($isArabic);
        $pdf->SetMargins(15, 20, 15);
        $pdf->SetAutoPageBreak(true, 25);
        $pdf->AddPage();

        $this->drawPrintHeader($pdf, $printSettings, $labels['title']);

        if ($this->shouldDrawPatientInfo($printSettings, 'top')) {
            $this->drawPatientInfo($pdf, $reservation, $labels, 'clientInfo', $align);
        }

        $pdf->Ln(4);
        $pdf->SetFont('dejavusans', 'B', 12);
        $pdf->Cell(0, 8, $labels['reservationInfo'], 0, 1, $align, 1);
        $pdf->SetFont('dejavusans', '', 10);
        $this->pdfLabelValue($pdf, $labels['doctor'], 'Dr. ' . $reservation->doctor->name, $align);
        $this->pdfLabelValue($pdf, $labels['status'], $reservation->status, $align);
        $this->pdfLabelValue($pdf, $labels['appointment'], \Carbon\Carbon::parse($reservation->appointment_date)->format('M d, Y H:i'), $align);
        $this->pdfLabelValue($pdf, $labels['created'], \Carbon\Carbon::parse($reservation->created_at)->format('M d, Y H:i'), $align);
        if ($reservation->completed_at) {
            $this->pdfLabelValue($pdf, $labels['completedAt'], \Carbon\Carbon::parse($reservation->completed_at)->format('M d, Y H:i'), $align);
        }
        $pdf->Ln(4);

        if ($this->shouldDrawPatientInfo($printSettings, 'after_doctor')) {
            $this->drawPatientInfo($pdf, $reservation, $labels, 'clientInfo', $align);
        }

        $sections = [
            'notes' => $reservation->notes,
            'diagnosis' => $reservation->diagnosis,
            'treatment' => $reservation->treatment,
            'currentProcedures' => $reservation->current_procedures,
            'procedureNotes' => $reservation->procedure_notes,
            'nextProcedures' => $reservation->next_procedures,
        ];

        foreach ($sections as $labelKey => $value) {
            if (!$value) {
                continue;
            }

            $pdf->Ln(3);
            $pdf->SetFont('dejavusans', 'B', 11);
            $pdf->Cell(0, 7, $labels[$labelKey], 0, 1, $align, 1);
            $pdf->SetFont('dejavusans', '', 10);
            $pdf->MultiCell(0, 6, $value, 1, $align);
        }

        if ($reservation->requires_xray || $reservation->requires_lab) {
            $pdf->Ln(3);
            $pdf->SetFont('dejavusans', 'B', 11);
            $pdf->Cell(0, 7, $labels['requirements'], 0, 1, $align, 1);
            $pdf->SetFont('dejavusans', '', 10);
            if ($reservation->requires_xray) {
                $pdf->Cell(0, 6, '- ' . $labels['xrayRequired'], 0, 1, $align);
                if ($reservation->xray_notes) {
                    $pdf->MultiCell(0, 6, $labels['xrayNotes'] . ': ' . $reservation->xray_notes, 0, $align);
                }
            }
            if ($reservation->requires_lab) {
                $pdf->Cell(0, 6, '- ' . $labels['labRequired'], 0, 1, $align);
                if ($reservation->lab_notes) {
                    $pdf->MultiCell(0, 6, $labels['labNotes'] . ': ' . $reservation->lab_notes, 0, $align);
                }
            }
        }

        if ($this->shouldDrawPatientInfo($printSettings, 'bottom')) {
            $pdf->Ln(4);
            $this->drawPatientInfo($pdf, $reservation, $labels, 'clientInfo', $align);
        }

        $this->drawPrintFooter($pdf, $printSettings);

        $filePrefix = $isArabic ? 'reservation-details-ar' : 'reservation-details-en';
        $fileName = $filePrefix . '_' . $reservation->id . '_' . date('Y-m-d') . '.pdf';

        return response($pdf->Output($fileName, 'S'))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="' . $fileName . '"');
    }

    public function generateMedicinesPrescription(Request $request, Reservation $reservation)
    {
        $doctorIds = $this->getDoctorIds($request);

        if (!in_array($reservation->doctor_id, $doctorIds)) {
            return response()->json(['error' => 'You can only generate prescriptions for your own reservations'], 403);
        }

        if ($reservation->status !== 'completed') {
            return response()->json(['error' => 'Only completed reservations can have prescriptions generated'], 400);
        }

        $reservation->load(['client', 'doctor']);

        $lang = strtolower((string) ($request->query('lang') ?: $request->header('Accept-Language', 'en')));
        $isArabic = str_starts_with($lang, 'ar');

        $labels = $isArabic
            ? [
                'clinic' => 'العيادة الطبية',
                'title' => 'روشتة الأدوية',
                'patientInfo' => 'بيانات العميل',
                'doctorInfo' => 'بيانات الطبيب',
                'name' => 'الاسم',
                'email' => 'البريد الإلكتروني',
                'phone' => 'الهاتف',
                'whatsapp' => 'واتساب',
                'dob' => 'تاريخ الميلاد',
                'address' => 'العنوان',
                'job' => 'الوظيفة',
                'doctor' => 'الطبيب',
                'appointment' => 'موعد الحجز',
                'date' => 'التاريخ',
                'medicines' => 'الأدوية',
                'noMedicines' => 'لم يتم تسجيل أدوية.',
                'signature' => 'توقيع الطبيب',
                'na' => 'غير متوفر',
            ]
            : [
                'clinic' => 'Medical Clinic',
                'title' => 'Medicines Prescription',
                'patientInfo' => 'Client Information',
                'doctorInfo' => 'Doctor Information',
                'name' => 'Name',
                'email' => 'Email',
                'phone' => 'Phone',
                'whatsapp' => 'WhatsApp',
                'dob' => 'Date of Birth',
                'address' => 'Address',
                'job' => 'Job',
                'doctor' => 'Doctor',
                'appointment' => 'Appointment',
                'date' => 'Date',
                'medicines' => 'Medicines',
                'noMedicines' => 'No medicines recorded.',
                'signature' => 'Doctor Signature',
                'na' => 'N/A',
            ];

        $align = $isArabic ? 'R' : 'L';
        $printSettings = $this->printSettingsFor($reservation, $labels);

        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Medical Clinic System');
        $pdf->SetAuthor('Dr. ' . $reservation->doctor->name);
        $pdf->SetTitle($labels['title']);
        $pdf->SetSubject($labels['title'] . ' - ' . $reservation->client->name);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->setRTL($isArabic);
        $pdf->SetMargins(15, 20, 15);
        $pdf->SetAutoPageBreak(true, 25);
        $pdf->AddPage();
        $pdf->SetFont('dejavusans', '', 12);

        $this->drawPrintHeader($pdf, $printSettings, $labels['title']);

        $patientOptions = [
            'dob' => true,
            'address' => true,
            'job' => true,
        ];

        if ($this->shouldDrawPatientInfo($printSettings, 'top')) {
            $this->drawPatientInfo($pdf, $reservation, $labels, 'patientInfo', $align, $patientOptions);
        }

        $pdf->Ln(4);
        $pdf->SetFont('dejavusans', 'B', 12);
        $pdf->Cell(0, 8, $labels['doctorInfo'], 0, 1, $align, 1);
        $pdf->SetFont('dejavusans', '', 10);
        $this->pdfLabelValue($pdf, $labels['doctor'], 'Dr. ' . $reservation->doctor->name, $align);
        $this->pdfLabelValue($pdf, $labels['email'], $reservation->doctor->email ?: $labels['na'], $align);
        $this->pdfLabelValue($pdf, $labels['appointment'], \Carbon\Carbon::parse($reservation->appointment_date)->format('M d, Y H:i'), $align);
        $this->pdfLabelValue($pdf, $labels['date'], now()->format('M d, Y H:i'), $align);
        $pdf->Ln(4);

        if ($this->shouldDrawPatientInfo($printSettings, 'after_doctor')) {
            $this->drawPatientInfo($pdf, $reservation, $labels, 'patientInfo', $align, $patientOptions);
        }

        $pdf->Ln(6);
        $pdf->SetFont('dejavusans', 'B', 12);
        $pdf->Cell(0, 8, $labels['medicines'], 0, 1, $align, 1);
        $pdf->SetFont('dejavusans', '', 11);
        $pdf->MultiCell(0, 8, $reservation->treatment ?: $labels['noMedicines'], 1, $align);

        if ($this->shouldDrawPatientInfo($printSettings, 'bottom')) {
            $pdf->Ln(4);
            $this->drawPatientInfo($pdf, $reservation, $labels, 'patientInfo', $align, $patientOptions);
        }

        $pdf->Ln(14);
        $pdf->SetFont('dejavusans', '', 10);
        $pdf->Cell(0, 6, $labels['signature'] . ': Dr. ' . $reservation->doctor->name, 0, 1, $align);
        $pdf->Cell(0, 6, str_repeat('_', 40), 0, 1, $align);
        $this->drawPrintFooter($pdf, $printSettings);

        $filePrefix = $isArabic ? 'medicines-prescription-ar' : 'medicines-prescription-en';
        $fileName = $filePrefix . '_' . $reservation->id . '_' . date('Y-m-d') . '.pdf';

        return response($pdf->Output($fileName, 'S'))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="' . $fileName . '"');
    }

    private function formatChronicIllnesses(array $illnesses, bool $isArabic): string
    {
        $options = config('client.chronic_illnesses', []);
        $labels = array_map(function ($illness) use ($options, $isArabic) {
            $option = $options[$illness] ?? null;

            if (is_array($option)) {
                return $option[$isArabic ? 'ar' : 'en'] ?? ucwords(str_replace('_', ' ', $illness));
            }

            return ucwords(str_replace('_', ' ', $illness));
        }, $illnesses);

        return implode($isArabic ? '، ' : ', ', $labels);
    }

    private function pdfLabelValue(TCPDF $pdf, string $label, string $value, string $align): void
    {
        $pdf->Cell(50, 6, $label . ':', 0, 0, $align);
        $pdf->MultiCell(0, 6, $value, 0, $align);
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
