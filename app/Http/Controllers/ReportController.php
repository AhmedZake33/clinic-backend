<?php

namespace App\Http\Controllers;

use App\Http\Traits\ResolvesDoctor;
use App\Models\Financial;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Http\Request;
use TCPDF;

class ReportController extends Controller
{
    use ResolvesDoctor;
    public function summary(Request $request)
    {
        return response()->json($this->buildSummary($request));
    }

    public function exportPdf(Request $request)
    {
        $summary = $this->buildSummary($request);
        $locale = $this->resolveLocale($request);
        $isArabic = $locale === 'ar';

        $labels = $isArabic
            ? [
                'title' => 'تقرير النظام',
                'generatedAt' => 'تاريخ الإنشاء',
                'dateFrom' => 'من تاريخ',
                'dateTo' => 'إلى تاريخ',
                'doctor' => 'الطبيب',
                'allDoctors' => 'جميع الأطباء',
                'reservations' => 'ملخص الحجوزات',
                'totalReservations' => 'إجمالي الحجوزات',
                'pending' => 'قيد الانتظار',
                'confirmed' => 'مؤكد',
                'completed' => 'مكتمل',
                'cancelled' => 'ملغي',
                'requiresXray' => 'يتطلب أشعة',
                'requiresLab' => 'يتطلب تحاليل',
                'financials' => 'ملخص المالية',
                'totalAmount' => 'إجمالي المبلغ',
                'totalPaid' => 'إجمالي المدفوع',
                'totalRemaining' => 'إجمالي المتبقي',
                'totalRecords' => 'عدد السجلات',
                'currency' => 'جنيه',
            ]
            : [
                'title' => 'System Report',
                'generatedAt' => 'Generated At',
                'dateFrom' => 'Date From',
                'dateTo' => 'Date To',
                'doctor' => 'Doctor',
                'allDoctors' => 'All Doctors',
                'reservations' => 'Reservations Summary',
                'totalReservations' => 'Total Reservations',
                'pending' => 'Pending',
                'confirmed' => 'Confirmed',
                'completed' => 'Completed',
                'cancelled' => 'Cancelled',
                'requiresXray' => 'Requires X-Ray',
                'requiresLab' => 'Requires Lab Tests',
                'financials' => 'Financial Summary',
                'totalAmount' => 'Total Amount',
                'totalPaid' => 'Total Paid',
                'totalRemaining' => 'Total Remaining',
                'totalRecords' => 'Total Records',
                'currency' => 'EGP',
            ];

        $align = $isArabic ? 'R' : 'L';

        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Clinic System');
        $pdf->SetAuthor($summary['doctor_name'] ?? 'Clinic');
        $pdf->SetTitle($labels['title']);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->setRTL($isArabic);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->AddPage();
        $pdf->SetFont('dejavusans', '', 11);

        $pdf->SetFillColor(44, 90, 160);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('dejavusans', 'B', 14);
        $pdf->Cell(0, 10, $labels['title'], 0, 1, 'C', 1);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(4);

        $pdf->SetFont('dejavusans', '', 10);
        $pdf->Cell(55, 7, $labels['generatedAt'] . ':', 0, 0, $align);
        $pdf->Cell(0, 7, now()->format('Y-m-d H:i'), 0, 1, $align);
        $pdf->Cell(55, 7, $labels['dateFrom'] . ':', 0, 0, $align);
        $pdf->Cell(0, 7, $summary['date_from'] ?? '-', 0, 1, $align);
        $pdf->Cell(55, 7, $labels['dateTo'] . ':', 0, 0, $align);
        $pdf->Cell(0, 7, $summary['date_to'] ?? '-', 0, 1, $align);
        $pdf->Cell(55, 7, $labels['doctor'] . ':', 0, 0, $align);
        $pdf->Cell(0, 7, $summary['doctor_name'] ?? $labels['allDoctors'], 0, 1, $align);

        $pdf->Ln(5);
        $pdf->SetFont('dejavusans', 'B', 12);
        $pdf->Cell(0, 8, $labels['reservations'], 0, 1, $align);
        $pdf->SetFont('dejavusans', '', 10);

        $reservationRows = [
            [$labels['totalReservations'], $summary['reservations']['total'] ?? 0],
            [$labels['pending'], $summary['reservations']['pending'] ?? 0],
            [$labels['confirmed'], $summary['reservations']['confirmed'] ?? 0],
            [$labels['completed'], $summary['reservations']['completed'] ?? 0],
            [$labels['cancelled'], $summary['reservations']['cancelled'] ?? 0],
            [$labels['requiresXray'], $summary['reservations']['requires_xray'] ?? 0],
            [$labels['requiresLab'], $summary['reservations']['requires_lab'] ?? 0],
        ];

        foreach ($reservationRows as [$label, $value]) {
            $pdf->Cell(95, 7, $label, 1, 0, $align);
            $pdf->Cell(0, 7, (string) $value, 1, 1, $align);
        }

        $pdf->Ln(5);
        $pdf->SetFont('dejavusans', 'B', 12);
        $pdf->Cell(0, 8, $labels['financials'], 0, 1, $align);
        $pdf->SetFont('dejavusans', '', 10);

        $financialRows = [
            [$labels['totalAmount'], number_format((float) ($summary['financials']['total_amount'] ?? 0), 2) . ' ' . $labels['currency']],
            [$labels['totalPaid'], number_format((float) ($summary['financials']['total_paid'] ?? 0), 2) . ' ' . $labels['currency']],
            [$labels['totalRemaining'], number_format((float) ($summary['financials']['total_remaining'] ?? 0), 2) . ' ' . $labels['currency']],
            [$labels['totalRecords'], $summary['financials']['total_records'] ?? 0],
        ];

        foreach ($financialRows as [$label, $value]) {
            $pdf->Cell(95, 7, $label, 1, 0, $align);
            $pdf->Cell(0, 7, (string) $value, 1, 1, $align);
        }

        $fileName = $isArabic ? 'report-ar.pdf' : 'report-en.pdf';

        return response($pdf->Output($fileName, 'S'))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="' . $fileName . '"');
    }

    private function buildSummary(Request $request): array
    {
        $doctorId = $this->requireDoctorId($request);
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');

        $reservationsQuery = Reservation::query()->where('doctor_id', $doctorId)->where('status', '!=', 'cancelled');
        $financialsQuery = Financial::query()->where('doctor_id', $doctorId);

        if ($dateFrom) {
            $reservationsQuery->whereDate('appointment_date', '>=', $dateFrom);
            $financialsQuery->whereDate('created_at', '>=', $dateFrom);
        }

        if ($dateTo) {
            $reservationsQuery->whereDate('appointment_date', '<=', $dateTo);
            $financialsQuery->whereDate('created_at', '<=', $dateTo);
        }

        $doctorName = null;
        if ($doctorId) {
            $doctorName = User::where('id', $doctorId)->value('name');
        }

        return [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'doctor_id' => $doctorId,
            'doctor_name' => $doctorName,
            'reservations' => [
                'total' => (clone $reservationsQuery)->count(),
                'pending' => (clone $reservationsQuery)->where('status', 'pending')->count(),
                'confirmed' => (clone $reservationsQuery)->where('status', 'confirmed')->count(),
                'completed' => (clone $reservationsQuery)->where('status', 'completed')->count(),
                'cancelled' => (clone $reservationsQuery)->where('status', 'cancelled')->count(),
                'requires_xray' => (clone $reservationsQuery)->where('requires_xray', true)->count(),
                'requires_lab' => (clone $reservationsQuery)->where('requires_lab', true)->count(),
            ],
            'financials' => [
                'total_amount' => round((float) (clone $financialsQuery)->sum('amount'), 2),
                'total_paid' => round((float) (clone $financialsQuery)->sum('paid'), 2),
                'total_remaining' => round((float) (clone $financialsQuery)->sum('remaining'), 2),
                'total_records' => (clone $financialsQuery)->count(),
            ],
        ];
    }

    private function resolveLocale(Request $request): string
    {
        $lang = $request->query('lang') ?: $request->header('Accept-Language', 'en');
        $lang = strtolower((string) $lang);

        return str_starts_with($lang, 'ar') ? 'ar' : 'en';
    }
}
