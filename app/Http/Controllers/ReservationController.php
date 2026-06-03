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

    public function index(Request $request)
    {
        $doctorIds = $request->boolean('own_only')
            ? [$this->requireDoctorId($request)]
            : $this->getDoctorIds($request);

        $query = Reservation::with(['client', 'doctor', 'creator', 'archive.children'])
            ->whereIn('doctor_id', $doctorIds);

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
        // Search by client name or notes
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('client', function ($qc) use ($search) {
                    $qc->where('name', 'like', "%{$search}%");
                })->orWhere('notes', 'like', "%{$search}%");
            });
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

        $reservation->update(['status' => 'confirmed']);
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
        $doctorId = $this->requireDoctorId($request);

        // Only the assigned doctor (or their assistant) can generate prescription
        if ($reservation->doctor_id !== $doctorId) {
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
            ];

        $align = $isArabic ? 'R' : 'L';

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

        $pdf->SetFillColor(44, 90, 160);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('dejavusans', 'B', 16);
        $pdf->Cell(0, 12, $labels['clinic'], 0, 1, 'C', 1);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(4);
        $pdf->Cell(0, 10, $labels['title'], 0, 1, 'C');
        $pdf->Ln(4);

        $pdf->SetFont('dejavusans', 'B', 12);
        $pdf->Cell(0, 8, $labels['patientInfo'], 0, 1, $align, 1);
        $pdf->SetFont('dejavusans', '', 10);
        $pdf->Cell(50, 6, $labels['name'] . ':', 0, 0, $align);
        $pdf->Cell(0, 6, $reservation->client->name, 0, 1, $align);
        $pdf->Cell(50, 6, $labels['email'] . ':', 0, 0, $align);
        $pdf->Cell(0, 6, $reservation->client->email, 0, 1, $align);
        $pdf->Cell(50, 6, $labels['phone'] . ':', 0, 0, $align);
        $pdf->Cell(0, 6, $reservation->client->phone, 0, 1, $align);
        if ($reservation->client->whatsapp_number) {
            $pdf->Cell(50, 6, $labels['whatsapp'] . ':', 0, 0, $align);
            $pdf->Cell(0, 6, $reservation->client->whatsapp_number, 0, 1, $align);
        }
        if ($reservation->client->date_of_birth) {
            $pdf->Cell(50, 6, $labels['dob'] . ':', 0, 0, $align);
            $pdf->Cell(0, 6, \Carbon\Carbon::parse($reservation->client->date_of_birth)->format('M d, Y'), 0, 1, $align);
        }
        if ($reservation->client->address) {
            $pdf->Cell(50, 6, $labels['address'] . ':', 0, 0, $align);
            $pdf->MultiCell(0, 6, $reservation->client->address, 0, $align);
        }
        if ($reservation->client->job) {
            $pdf->Cell(50, 6, $labels['job'] . ':', 0, 0, $align);
            $pdf->Cell(0, 6, $reservation->client->job, 0, 1, $align);
        }
        if (!empty($reservation->client->chronic_illnesses)) {
            $pdf->Cell(50, 6, $labels['chronicIllnesses'] . ':', 0, 0, $align);
            $pdf->MultiCell(0, 6, $this->formatChronicIllnesses($reservation->client->chronic_illnesses, $isArabic), 0, $align);
        }

        $pdf->Ln(4);
        $pdf->SetFont('dejavusans', 'B', 12);
        $pdf->Cell(0, 8, $labels['doctorInfo'], 0, 1, $align, 1);
        $pdf->SetFont('dejavusans', '', 10);
        $pdf->Cell(50, 6, $labels['doctor'] . ':', 0, 0, $align);
        $pdf->Cell(0, 6, 'Dr. ' . $reservation->doctor->name, 0, 1, $align);
        $pdf->Cell(50, 6, $labels['date'] . ':', 0, 0, $align);
        $pdf->Cell(0, 6, \Carbon\Carbon::parse($reservation->appointment_date)->format('M d, Y H:i'), 0, 1, $align);

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

        $pdf->Ln(12);
        $pdf->SetFont('dejavusans', '', 10);
        $pdf->Cell(0, 6, $labels['signature'] . ': Dr. ' . $reservation->doctor->name, 0, 1, $align);
        $pdf->Cell(0, 6, str_repeat('_', 40), 0, 1, $align);
        $pdf->Ln(5);
        $pdf->SetFont('dejavusans', $isArabic ? '' : 'I', 8);
        $pdf->MultiCell(0, 6, $labels['notes'], 0, 'C');

        $filePrefix = $isArabic ? 'prescription-ar' : 'prescription-en';
        $fileName = $filePrefix . '_' . $reservation->id . '_' . date('Y-m-d') . '.pdf';
        
        return response($pdf->Output($fileName, 'S'))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="' . $fileName . '"');
    }

    public function generateReservationDetailsPdf(Request $request, Reservation $reservation)
    {
        $doctorId = $this->requireDoctorId($request);

        if ($reservation->doctor_id !== $doctorId) {
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

        $pdf->SetFillColor(44, 90, 160);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('dejavusans', 'B', 16);
        $pdf->Cell(0, 12, $labels['clinic'], 0, 1, 'C', 1);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(4);
        $pdf->Cell(0, 10, $labels['title'], 0, 1, 'C');
        $pdf->Ln(4);

        $pdf->SetFont('dejavusans', 'B', 12);
        $pdf->Cell(0, 8, $labels['clientInfo'], 0, 1, $align, 1);
        $pdf->SetFont('dejavusans', '', 10);
        $this->pdfLabelValue($pdf, $labels['name'], $reservation->client->name, $align);
        $this->pdfLabelValue($pdf, $labels['email'], $reservation->client->email ?: $labels['na'], $align);
        $this->pdfLabelValue($pdf, $labels['phone'], $reservation->client->phone ?: $labels['na'], $align);
        $this->pdfLabelValue($pdf, $labels['whatsapp'], $reservation->client->whatsapp_number ?: $labels['na'], $align);

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

        $filePrefix = $isArabic ? 'reservation-details-ar' : 'reservation-details-en';
        $fileName = $filePrefix . '_' . $reservation->id . '_' . date('Y-m-d') . '.pdf';

        return response($pdf->Output($fileName, 'S'))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="' . $fileName . '"');
    }

    public function generateMedicinesPrescription(Request $request, Reservation $reservation)
    {
        $doctorId = $this->requireDoctorId($request);

        if ($reservation->doctor_id !== $doctorId) {
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

        $pdf->SetFillColor(44, 90, 160);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('dejavusans', 'B', 16);
        $pdf->Cell(0, 12, $labels['clinic'], 0, 1, 'C', 1);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(4);
        $pdf->Cell(0, 10, $labels['title'], 0, 1, 'C');
        $pdf->Ln(6);

        $pdf->SetFont('dejavusans', 'B', 12);
        $pdf->Cell(0, 8, $labels['patientInfo'], 0, 1, $align, 1);
        $pdf->SetFont('dejavusans', '', 10);
        $this->pdfLabelValue($pdf, $labels['name'], $reservation->client->name, $align);
        $this->pdfLabelValue($pdf, $labels['email'], $reservation->client->email ?: $labels['na'], $align);
        $this->pdfLabelValue($pdf, $labels['phone'], $reservation->client->phone ?: $labels['na'], $align);
        $this->pdfLabelValue($pdf, $labels['whatsapp'], $reservation->client->whatsapp_number ?: $labels['na'], $align);
        if ($reservation->client->date_of_birth) {
            $this->pdfLabelValue($pdf, $labels['dob'], \Carbon\Carbon::parse($reservation->client->date_of_birth)->format('M d, Y'), $align);
        }
        if ($reservation->client->address) {
            $this->pdfLabelValue($pdf, $labels['address'], $reservation->client->address, $align);
        }
        if ($reservation->client->job) {
            $this->pdfLabelValue($pdf, $labels['job'], $reservation->client->job, $align);
        }

        $pdf->Ln(4);
        $pdf->SetFont('dejavusans', 'B', 12);
        $pdf->Cell(0, 8, $labels['doctorInfo'], 0, 1, $align, 1);
        $pdf->SetFont('dejavusans', '', 10);
        $this->pdfLabelValue($pdf, $labels['doctor'], 'Dr. ' . $reservation->doctor->name, $align);
        $this->pdfLabelValue($pdf, $labels['email'], $reservation->doctor->email ?: $labels['na'], $align);
        $this->pdfLabelValue($pdf, $labels['appointment'], \Carbon\Carbon::parse($reservation->appointment_date)->format('M d, Y H:i'), $align);
        $this->pdfLabelValue($pdf, $labels['date'], now()->format('M d, Y H:i'), $align);

        $pdf->Ln(6);
        $pdf->SetFont('dejavusans', 'B', 12);
        $pdf->Cell(0, 8, $labels['medicines'], 0, 1, $align, 1);
        $pdf->SetFont('dejavusans', '', 11);
        $pdf->MultiCell(0, 8, $reservation->treatment ?: $labels['noMedicines'], 1, $align);

        $pdf->Ln(14);
        $pdf->SetFont('dejavusans', '', 10);
        $pdf->Cell(0, 6, $labels['signature'] . ': Dr. ' . $reservation->doctor->name, 0, 1, $align);
        $pdf->Cell(0, 6, str_repeat('_', 40), 0, 1, $align);

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
}
