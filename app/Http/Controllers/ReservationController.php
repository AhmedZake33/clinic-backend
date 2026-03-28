<?php

namespace App\Http\Controllers;

use App\Events\ReservationCreated;
use App\Events\ReservationUpdated;
use App\Events\ReservationCompleted;
use App\Events\ReservationDeleted;
use App\Http\Traits\ResolvesDoctor;
use App\Models\Financial;
use App\Models\Archive;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Http\Request;
use TCPDF;

class ReservationController extends Controller
{
    use ResolvesDoctor;

    public function index(Request $request)
    {
        $doctorId = $this->requireDoctorId($request);

        $query = Reservation::with(['client', 'doctor', 'creator', 'archive.children'])
            ->where('doctor_id', $doctorId);

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
        $doctorId = $this->requireDoctorId($request);

        $request->validate([
            'client_id' => 'required|exists:clients,id',
            'appointment_date' => 'required|string',
            'notes' => 'nullable|string',
            'amount' => 'required|numeric|min:0',
            'paid' => 'nullable|numeric|min:0',
            'payment_method' => 'required|in:cash,card,transfer,other',
        ]);

        // Use the tenant doctor
        $doctor = User::where('id', $doctorId)->where('role', 'doctor')->firstOrFail();

        // Verify client belongs to this doctor
        $client = \App\Models\Client::where('id', $request->client_id)
            ->where('doctor_id', $doctorId)
            ->first();
        if (!$client) {
            return response()->json(['error' => 'Client does not belong to this doctor'], 422);
        }

        // Validate appointment against doctor's schedule and holidays
        $appointment = \Carbon\Carbon::parse($request->appointment_date);
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

        // Prevent double booking at exact same timestamp
        $conflict = Reservation::where('doctor_id', $doctor->id)
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

        $reservation->load(['client', 'doctor', 'creator']);

        // Create financial record for this reservation
        $amount = $request->amount;
        $paid = $request->paid ?? 0;
        $remaining = $amount - $paid;
        $paymentStatus = $paid <= 0 ? 'unpaid' : ($paid >= $amount ? 'paid' : 'partial');

        Financial::create([
            'reservation_id' => $reservation->id,
            'client_id' => $request->client_id,
            'doctor_id' => $doctorId,
            'created_by' => $request->user()->id,
            'amount' => $amount,
            'paid' => $paid,
            'remaining' => $remaining,
            'payment_status' => $paymentStatus,
            'payment_method' => $request->payment_method,
            'notes' => null,
        ]);

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
        $doctorId = $this->requireDoctorId($request);

        if ($reservation->doctor_id !== $doctorId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $reservation->load(['client', 'doctor', 'creator', 'archive.children']);
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
        $doctorId = $this->requireDoctorId($request);

        if ($reservation->doctor_id !== $doctorId) {
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
        $reservation->load(['client', 'doctor', 'creator']);

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
        $reservation->load(['client', 'doctor', 'creator']);

        broadcast(new ReservationUpdated($reservation))->toOthers();

        return response()->json($reservation);
    }

    public function complete(Request $request, Reservation $reservation)
    {
        // Only doctors can complete reservations
        if ($request->user()->role !== 'doctor') {
            return response()->json(['error' => 'Only doctors can complete reservations'], 403);
        }

        // Only the assigned doctor can complete
        if ($reservation->doctor_id !== $request->user()->id) {
            return response()->json(['error' => 'You can only complete your own reservations'], 403);
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

        if ($request->hasFile('files')) {
            $archiveFolder = $this->ensureReservationArchiveFolder($reservation);

            foreach ($request->file('files') as $file) {
                Archive::createFile($archiveFolder, $file, [
                    'content_type' => 'reservation_completion_file',
                    'language' => app()->getLocale(),
                ]);
            }
        }

        $reservation->load(['client', 'doctor', 'creator', 'archive.children']);

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
        $doctorId = $this->requireDoctorId($request);

        if ($reservation->doctor_id !== $doctorId) {
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
        // If assistant, only return their assigned doctor
        if ($request->user()->role === 'assistant') {
            $doctorId = $request->user()->doctor_id;
            $doctors = User::where('id', $doctorId)->where('role', 'doctor')->get(['id', 'name', 'email']);
            return response()->json($doctors);
        }

        // If doctor, return only themselves
        if ($request->user()->role === 'doctor') {
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
}
