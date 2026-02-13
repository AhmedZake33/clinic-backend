<?php

namespace App\Http\Controllers;

use App\Events\ReservationCreated;
use App\Events\ReservationUpdated;
use App\Events\ReservationCompleted;
use App\Events\ReservationDeleted;
use App\Models\Financial;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Http\Request;
use TCPDF;

class ReservationController extends Controller
{
    public function index(Request $request)
    {
        $query = Reservation::with(['client', 'doctor', 'creator']);

        // Filter by role
        if ($request->user()->role === 'doctor') {
            $query->where('doctor_id', $request->user()->id);
        }

        // Optional filters
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        // Assistant can filter by doctor_id; doctor filter is already enforced
        if ($request->user()->role !== 'doctor') {
            if ($doctorId = $request->query('doctor_id')) {
                $query->where('doctor_id', $doctorId);
            }
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
        $request->validate([
            'client_id' => 'required|exists:clients,id',
            'doctor_id' => 'required|exists:users,id',
            'appointment_date' => 'required|string',
            'notes' => 'nullable|string',
            'amount' => 'required|numeric|min:0',
            'paid' => 'nullable|numeric|min:0',
            'payment_method' => 'required|in:cash,card,transfer,other',
        ]);

        // Verify doctor role
        $doctor = User::findOrFail($request->doctor_id);
        if ($doctor->role !== 'doctor') {
            return response()->json(['error' => 'Selected user is not a doctor'], 422);
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
            'doctor_id' => $request->doctor_id,
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
            'doctor_id' => $request->doctor_id,
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

    public function show(Reservation $reservation)
    {
        $reservation->load(['client', 'doctor', 'creator']);
        return response()->json($reservation);
    }

    public function update(Request $request, Reservation $reservation)
    {
        $request->validate([
            'client_id' => 'required|exists:clients,id',
            'doctor_id' => 'required|exists:users,id',
            'appointment_date' => 'required|string',
            'status' => 'required|in:pending,confirmed,completed,cancelled',
            'notes' => 'nullable|string',
            'diagnosis' => 'nullable|string',
            'treatment' => 'nullable|string',
        ]);

        $reservation->update($request->all());
        $reservation->load(['client', 'doctor', 'creator']);

        // Broadcast event for real-time updates (exclude the sender)
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
        ]);

        $reservation->update([
            'status' => 'completed',
            'diagnosis' => $request->diagnosis,
            'treatment' => $request->treatment,
            'completed_at' => now(),
        ]);

        $reservation->load(['client', 'doctor', 'creator']);

        // Broadcast event for real-time updates (exclude the sender)
        broadcast(new ReservationCompleted($reservation))->toOthers();

        return response()->json($reservation);
    }

    public function destroy(Reservation $reservation)
    {
        $reservationId = $reservation->id;
        $doctorId = $reservation->doctor_id;
        $clientName = $reservation->client->name ?? 'Unknown';

        $reservation->delete();

        // Broadcast event for real-time updates (exclude the sender)
        broadcast(new ReservationDeleted($reservationId, $doctorId, $clientName))->toOthers();

        return response()->json(['message' => 'Reservation deleted successfully']);
    }

    public function doctors()
    {
        $doctors = User::where('role', 'doctor')->get(['id', 'name', 'email']);
        return response()->json($doctors);
    }

    public function generatePrescription(Request $request, Reservation $reservation)
    {
        // Only doctors can generate prescriptions
        if ($request->user()->role !== 'doctor') {
            return response()->json(['error' => 'Only doctors can generate prescriptions'], 403);
        }

        // Only the assigned doctor can generate prescription
        if ($reservation->doctor_id !== $request->user()->id) {
            return response()->json(['error' => 'You can only generate prescriptions for your own reservations'], 403);
        }

        // Only completed reservations can have prescriptions
        if ($reservation->status !== 'completed') {
            return response()->json(['error' => 'Only completed reservations can have prescriptions generated'], 400);
        }

        // Load relationships
        $reservation->load(['client', 'doctor', 'creator']);

        // Create new PDF document
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        
        // Set document information
        $pdf->SetCreator('Medical Clinic System');
        $pdf->SetAuthor('Dr. ' . $reservation->doctor->name);
        $pdf->SetTitle('Medical Prescription');
        $pdf->SetSubject('Prescription for ' . $reservation->client->name);
        
        // Remove default header/footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        
        // Enable RTL for Arabic support
        $pdf->setRTL(true);
        
        // Set margins
        $pdf->SetMargins(15, 20, 15);
        $pdf->SetAutoPageBreak(true, 25);
        
        // Add a page
        $pdf->AddPage();
        
        // Set font for Arabic support
        $pdf->SetFont('dejavusans', '', 12);
        
        // Clinic Header - Bilingual Arabic/English
        $pdf->SetFillColor(44, 90, 160);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->Cell(0, 12, 'العيادة الطبية - Medical Clinic', 0, 1, 'C', 1);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(5);
        
        // Title - Bilingual
        $pdf->SetFont('dejavusans', 'B', 16);
        $pdf->Cell(0, 10, 'الوصفة الطبية - MEDICAL PRESCRIPTION', 0, 1, 'C');
        $pdf->Ln(10);
        
        // Patient Information - Bilingual
        $pdf->SetFont('dejavusans', 'B', 12);
        $pdf->SetFillColor(248, 249, 250);
        $pdf->Cell(0, 8, 'معلومات المريض - Patient Information', 0, 1, 'C', 1);
        $pdf->Ln(2);
        
        $pdf->SetFont('dejavusans', '', 10);
        $pdf->Cell(50, 6, 'الاسم - Name:', 0, 0, 'R');
        $pdf->Cell(0, 6, $reservation->client->name, 0, 1, 'R');
        $pdf->Cell(50, 6, 'البريد - Email:', 0, 0, 'R');
        $pdf->Cell(0, 6, $reservation->client->email, 0, 1, 'R');
        $pdf->Cell(50, 6, 'الهاتف - Phone:', 0, 0, 'R');
        $pdf->Cell(0, 6, $reservation->client->phone, 0, 1, 'R');
        
        if ($reservation->client->date_of_birth) {
            $pdf->Cell(50, 6, 'تاريخ الميلاد - DOB:', 0, 0, 'R');
            $pdf->Cell(0, 6, \Carbon\Carbon::parse($reservation->client->date_of_birth)->format('M d, Y'), 0, 1, 'R');
        }
        
        $pdf->Ln(5);
        
        // Doctor Information - Bilingual
        $pdf->SetFont('dejavusans', 'B', 12);
        $pdf->Cell(0, 8, 'معلومات الطبيب - Doctor Information', 0, 1, 'C', 1);
        $pdf->Ln(2);
        
        $pdf->SetFont('dejavusans', '', 10);
        $pdf->Cell(50, 6, 'الطبيب - Doctor:', 0, 0, 'R');
        $pdf->Cell(0, 6, 'د. ' . $reservation->doctor->name, 0, 1, 'R');
        $pdf->Cell(50, 6, 'التاريخ - Date:', 0, 0, 'R');
        $pdf->Cell(0, 6, \Carbon\Carbon::parse($reservation->appointment_date)->format('M d, Y \\a\\t H:i A'), 0, 1, 'R');
        
        $pdf->Ln(10);
        
        // Diagnosis Section - Bilingual
        $pdf->SetFont('dejavusans', 'B', 12);
        $pdf->Cell(0, 8, 'التشخيص - Diagnosis', 0, 1, 'C', 1);
        $pdf->Ln(2);
        
        $pdf->SetFont('dejavusans', '', 10);
        $diagnosis = $reservation->diagnosis ?? 'لم يتم تقديم تشخيص - No diagnosis provided.';
        $pdf->MultiCell(0, 6, $diagnosis, 1, 'R');
        
        $pdf->Ln(5);
        
        // Treatment Section - Bilingual
        $pdf->SetFont('dejavusans', 'B', 12);
        $pdf->Cell(0, 8, 'خطة العلاج والوصفة - Treatment Plan & Prescription', 0, 1, 'C', 1);
        $pdf->Ln(2);
        
        $pdf->SetFont('dejavusans', '', 10);
        $treatment = $reservation->treatment ?? 'لم يتم تقديم خطة علاج - No treatment plan provided.';
        $pdf->MultiCell(0, 6, $treatment, 1, 'R');
        
        $pdf->Ln(5);
        
        // Medical History (if available) - Bilingual
        if ($reservation->client->medical_history) {
            $pdf->SetFont('dejavusans', 'B', 12);
            $pdf->Cell(0, 8, 'التاريخ المرضي - Medical History', 0, 1, 'C', 1);
            $pdf->Ln(2);
            
            $pdf->SetFont('dejavusans', '', 10);
            $pdf->MultiCell(0, 6, $reservation->client->medical_history, 1, 'R');
            $pdf->Ln(5);
        }
        
        // Footer with signature - Bilingual
        $pdf->Ln(20);
        $pdf->SetFont('dejavusans', '', 10);
        $pdf->Cell(100, 6, 'التاريخ - Date: ' . \Carbon\Carbon::now()->format('F d, Y'), 0, 0, 'R');
        $pdf->Cell(0, 6, 'د. ' . $reservation->doctor->name, 0, 1, 'L');
        $pdf->Cell(100, 6, '', 0, 0, 'L');
        $pdf->Cell(0, 6, str_repeat('_', 30), 0, 1, 'L');
        $pdf->Cell(100, 6, '', 0, 0, 'L');
        $pdf->Cell(0, 6, 'التوقيع الرقمي - Digital Signature', 0, 1, 'L');
        
        $pdf->Ln(10);
        $pdf->SetFont('dejavusans', 'I', 8);
        $pdf->Cell(0, 6, 'هذه وصفة طبية مولدة رقمياً. يرجى استشارة طبيبك لأي أسئلة أو مخاوف.', 0, 1, 'C');
        $pdf->Cell(0, 6, 'This is a digitally generated prescription. Please consult with your doctor for any questions or concerns.', 0, 1, 'C');

        $fileName = 'prescription_' . $reservation->id . '_' . date('Y-m-d') . '.pdf';
        
        return response($pdf->Output($fileName, 'S'))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="' . $fileName . '"');
    }
}
