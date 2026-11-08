<?php

namespace App\Services\Reservations;

use App\Models\Reservation;
use Carbon\Carbon;
use Illuminate\Http\Response;
use TCPDF;

/**
 * Builds the printable PDF documents for a reservation
 * (prescription, reservation details, medicines prescription).
 */
class ReservationPdfService
{
    /**
     * Full medical prescription (diagnosis, treatment, procedures, requirements).
     */
    public function prescription(Reservation $reservation, bool $isArabic): Response
    {
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

        $pdf = $this->newDocument($reservation, $labels['title'], $isArabic);
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
        $pdf->Cell(0, 6, Carbon::parse($reservation->appointment_date)->format('M d, Y H:i'), 0, 1, $align);
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

        $this->drawRequirements($pdf, $reservation, $labels, $isArabic ? 'متطلبات إضافية' : 'Additional Requirements', $align, 4, 12, 8);

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

        return $this->download($pdf, $isArabic ? 'prescription-ar' : 'prescription-en', $reservation);
    }

    /**
     * Reservation details sheet.
     */
    public function reservationDetails(Reservation $reservation, bool $isArabic): Response
    {
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

        $pdf = $this->newDocument($reservation, $labels['title'], $isArabic);

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
        $this->pdfLabelValue($pdf, $labels['appointment'], Carbon::parse($reservation->appointment_date)->format('M d, Y H:i'), $align);
        $this->pdfLabelValue($pdf, $labels['created'], Carbon::parse($reservation->created_at)->format('M d, Y H:i'), $align);
        if ($reservation->completed_at) {
            $this->pdfLabelValue($pdf, $labels['completedAt'], Carbon::parse($reservation->completed_at)->format('M d, Y H:i'), $align);
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

        $this->drawRequirements($pdf, $reservation, $labels, $labels['requirements'], $align, 3, 11, 7);

        if ($this->shouldDrawPatientInfo($printSettings, 'bottom')) {
            $pdf->Ln(4);
            $this->drawPatientInfo($pdf, $reservation, $labels, 'clientInfo', $align);
        }

        $this->drawPrintFooter($pdf, $printSettings);

        return $this->download($pdf, $isArabic ? 'reservation-details-ar' : 'reservation-details-en', $reservation);
    }

    /**
     * Medicines-only prescription.
     */
    public function medicinesPrescription(Reservation $reservation, bool $isArabic): Response
    {
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

        $pdf = $this->newDocument($reservation, $labels['title'], $isArabic);
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
        $this->pdfLabelValue($pdf, $labels['appointment'], Carbon::parse($reservation->appointment_date)->format('M d, Y H:i'), $align);
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

        return $this->download($pdf, $isArabic ? 'medicines-prescription-ar' : 'medicines-prescription-en', $reservation);
    }

    // ---------------------------------------------------------------------
    // Shared drawing helpers
    // ---------------------------------------------------------------------

    private function newDocument(Reservation $reservation, string $title, bool $isArabic): TCPDF
    {
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Medical Clinic System');
        $pdf->SetAuthor('Dr. ' . $reservation->doctor->name);
        $pdf->SetTitle($title);
        $pdf->SetSubject($title . ' - ' . $reservation->client->name);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->setRTL($isArabic);
        $pdf->SetMargins(15, 20, 15);
        $pdf->SetAutoPageBreak(true, 25);
        $pdf->AddPage();

        return $pdf;
    }

    private function download(TCPDF $pdf, string $filePrefix, Reservation $reservation): Response
    {
        $fileName = $filePrefix . '_' . $reservation->id . '_' . date('Y-m-d') . '.pdf';

        return response($pdf->Output($fileName, 'S'))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="' . $fileName . '"');
    }

    /**
     * X-ray / lab requirements block (drawn only when one of them is required).
     */
    private function drawRequirements(
        TCPDF $pdf,
        Reservation $reservation,
        array $labels,
        string $title,
        string $align,
        int $spacing,
        int $titleSize,
        int $titleHeight
    ): void {
        if (!$reservation->requires_xray && !$reservation->requires_lab) {
            return;
        }

        $pdf->Ln($spacing);
        $pdf->SetFont('dejavusans', 'B', $titleSize);
        $pdf->Cell(0, $titleHeight, $title, 0, 1, $align, 1);
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
            $this->pdfLabelValue($pdf, $labels['dob'], Carbon::parse($reservation->client->date_of_birth)->format('M d, Y'), $align);
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
