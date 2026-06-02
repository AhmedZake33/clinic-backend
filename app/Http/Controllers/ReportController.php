<?php

namespace App\Http\Controllers;

use App\Http\Traits\ResolvesDoctor;
use App\Models\Financial;
use App\Models\Purchase;
use App\Models\Reservation;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use TCPDF;

class ReportController extends Controller
{
    use ResolvesDoctor;

    private const ADDITIONAL_SERVICES_INVOICE_NOTE = 'Additional services invoice';
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

        $labels = array_merge($labels, [
            'incomeByInvoiceType' => 'Income by Invoice Type',
            'incomeByPaymentMethod' => 'Income by Payment Method',
            'reservationPaymentsByMethod' => 'Reservation Payments by Method',
            'additionalServicesPaymentsByMethod' => 'Additional Services Payments by Method',
            'purchasesSummary' => 'Purchases Summary',
            'purchasesByCategory' => 'Purchases by Category',
            'purchasesByPaymentMethod' => 'Purchases by Payment Method',
            'reservationInvoice' => 'Reservation Invoice',
            'additionalServicesInvoice' => 'Additional Services Invoice',
            'breakdown' => 'Breakdown',
            'amount' => 'Amount',
            'count' => 'Count',
            'noData' => 'No data',
        ]);

        if ($isArabic) {
            $labels = array_merge($labels, [
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
                'incomeByInvoiceType' => 'الدخل حسب نوع الفاتورة',
                'incomeByPaymentMethod' => 'الدخل حسب طريقة الدفع',
                'reservationPaymentsByMethod' => 'مدفوعات الحجوزات حسب طريقة الدفع',
                'additionalServicesPaymentsByMethod' => 'مدفوعات الخدمات الإضافية حسب طريقة الدفع',
                'purchasesSummary' => 'ملخص المشتريات',
                'purchasesByCategory' => 'المشتريات حسب التصنيف',
                'purchasesByPaymentMethod' => 'المشتريات حسب طريقة الدفع',
                'reservationInvoice' => 'فاتورة حجز',
                'additionalServicesInvoice' => 'فاتورة خدمات إضافية',
                'breakdown' => 'التقسيم',
                'amount' => 'المبلغ',
                'count' => 'العدد',
                'noData' => 'لا توجد بيانات',
            ]);
        }

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
            $pdf->Cell(95, 7, $label, 1, 0, 'C');
            $pdf->Cell(0, 7, (string) $value, 1, 1, 'C');
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
            $pdf->Cell(95, 7, $label, 1, 0, 'C');
            $pdf->Cell(0, 7, (string) $value, 1, 1, 'C');
        }

        $payments = $summary['financials']['payments'] ?? [];
        $invoiceTypeRows = [
            [
                'label' => $labels['reservationInvoice'],
                'amount' => $payments['reservation_invoices']['amount'] ?? 0,
                'count' => $payments['reservation_invoices']['count'] ?? 0,
            ],
            [
                'label' => $labels['additionalServicesInvoice'],
                'amount' => $payments['additional_services']['amount'] ?? 0,
                'count' => $payments['additional_services']['count'] ?? 0,
            ],
        ];

        $this->pdfBreakdownTable($pdf, $labels['incomeByInvoiceType'], $invoiceTypeRows, $labels, $align, 'plain', $locale);
        $this->pdfBreakdownTable($pdf, $labels['incomeByPaymentMethod'], $payments['by_payment_method'] ?? [], $labels, $align, 'payment', $locale);
        $this->pdfBreakdownTable($pdf, $labels['reservationPaymentsByMethod'], $payments['reservation_by_payment_method'] ?? [], $labels, $align, 'payment', $locale);
        $this->pdfBreakdownTable($pdf, $labels['additionalServicesPaymentsByMethod'], $payments['additional_services_by_payment_method'] ?? [], $labels, $align, 'payment', $locale);

        $pdf->Ln(5);
        $pdf->SetFont('dejavusans', 'B', 12);
        $pdf->Cell(0, 8, $labels['purchasesSummary'], 0, 1, $align);
        $pdf->SetFont('dejavusans', '', 10);

        $purchaseRows = [
            [$labels['totalAmount'], number_format((float) ($summary['purchases']['total_amount'] ?? 0), 2) . ' ' . $labels['currency']],
            [$labels['totalRecords'], $summary['purchases']['total_records'] ?? 0],
        ];

        foreach ($purchaseRows as [$label, $value]) {
            $pdf->Cell(95, 7, $label, 1, 0, 'C');
            $pdf->Cell(0, 7, (string) $value, 1, 1, 'C');
        }

        $this->pdfBreakdownTable($pdf, $labels['purchasesByCategory'], $summary['purchases']['by_category'] ?? [], $labels, $align, 'category', $locale);
        $this->pdfBreakdownTable($pdf, $labels['purchasesByPaymentMethod'], $summary['purchases']['by_payment_method'] ?? [], $labels, $align, 'payment', $locale);

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
        $financialsQuery = Financial::query()
            ->where('doctor_id', $doctorId)
            ->where('voided', false);
        $transactionsQuery = Transaction::query()
            ->join('financials', 'transactions.financial_id', '=', 'financials.id')
            ->where('transactions.doctor_id', $doctorId)
            ->where('financials.voided', false);
        $purchasesQuery = Purchase::query()
            ->where('doctor_id', $doctorId);

        if ($dateFrom) {
            $reservationsQuery->whereDate('appointment_date', '>=', $dateFrom);
            $financialsQuery->whereDate('created_at', '>=', $dateFrom);
            $transactionsQuery->whereDate('transactions.created_at', '>=', $dateFrom);
            $purchasesQuery->whereDate('purchase_date', '>=', $dateFrom);
        }

        if ($dateTo) {
            $reservationsQuery->whereDate('appointment_date', '<=', $dateTo);
            $financialsQuery->whereDate('created_at', '<=', $dateTo);
            $transactionsQuery->whereDate('transactions.created_at', '<=', $dateTo);
            $purchasesQuery->whereDate('purchase_date', '<=', $dateTo);
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
                'payments' => [
                    'total' => $this->transactionTotals(clone $transactionsQuery),
                    'reservation_invoices' => $this->transactionTotals($this->onlyReservationInvoiceTransactions(clone $transactionsQuery)),
                    'additional_services' => $this->transactionTotals($this->onlyAdditionalServiceTransactions(clone $transactionsQuery)),
                    'by_payment_method' => $this->transactionBreakdownByPaymentMethod(clone $transactionsQuery),
                    'reservation_by_payment_method' => $this->transactionBreakdownByPaymentMethod($this->onlyReservationInvoiceTransactions(clone $transactionsQuery)),
                    'additional_services_by_payment_method' => $this->transactionBreakdownByPaymentMethod($this->onlyAdditionalServiceTransactions(clone $transactionsQuery)),
                ],
            ],
            'purchases' => [
                'total_amount' => round((float) (clone $purchasesQuery)->sum('amount_paid'), 2),
                'total_records' => (clone $purchasesQuery)->count(),
                'by_category' => $this->purchaseBreakdown(clone $purchasesQuery, 'category'),
                'by_payment_method' => $this->purchaseBreakdown(clone $purchasesQuery, 'payment_method'),
            ],
        ];
    }

    private function transactionTotals($query): array
    {
        return [
            'amount' => round((float) $query->sum('transactions.amount'), 2),
            'count' => (clone $query)->count('transactions.id'),
        ];
    }

    private function onlyAdditionalServiceTransactions($query)
    {
        return $query->where(function ($q) {
            $q->whereNotNull('financials.reservation_service_id')
                ->orWhere('financials.notes', 'like', self::ADDITIONAL_SERVICES_INVOICE_NOTE . '%');
        });
    }

    private function onlyReservationInvoiceTransactions($query)
    {
        return $query->whereNull('financials.reservation_service_id')
            ->where(function ($q) {
                $q->whereNull('financials.notes')
                    ->orWhere('financials.notes', 'not like', self::ADDITIONAL_SERVICES_INVOICE_NOTE . '%');
            });
    }

    private function transactionBreakdownByPaymentMethod($query): array
    {
        return $query
            ->select('transactions.payment_method', DB::raw('SUM(transactions.amount) as amount'), DB::raw('COUNT(transactions.id) as count'))
            ->groupBy('transactions.payment_method')
            ->orderBy('transactions.payment_method')
            ->get()
            ->map(fn ($row) => [
                'key' => $row->payment_method,
                'label' => $row->payment_method,
                'amount' => round((float) $row->amount, 2),
                'count' => (int) $row->count,
            ])
            ->values()
            ->all();
    }

    private function purchaseBreakdown($query, string $column): array
    {
        return $query
            ->select($column, DB::raw('SUM(amount_paid) as amount'), DB::raw('COUNT(id) as count'))
            ->groupBy($column)
            ->orderBy($column)
            ->get()
            ->map(fn ($row) => [
                'key' => $row->{$column},
                'label' => $row->{$column},
                'amount' => round((float) $row->amount, 2),
                'count' => (int) $row->count,
            ])
            ->values()
            ->all();
    }

    private function pdfBreakdownTable(TCPDF $pdf, string $title, array $rows, array $labels, string $align, string $labelType, string $locale): void
    {
        $pdf->Ln(5);
        $pdf->SetFont('dejavusans', 'B', 12);
        $pdf->Cell(0, 8, $title, 0, 1, $align);

        $pdf->SetFont('dejavusans', 'B', 9);
        $pdf->Cell(85, 7, $labels['breakdown'], 1, 0, 'C');
        $pdf->Cell(55, 7, $labels['amount'], 1, 0, 'C');
        $pdf->Cell(0, 7, $labels['count'], 1, 1, 'C');

        $pdf->SetFont('dejavusans', '', 9);
        if (!$rows) {
            $pdf->Cell(0, 7, $labels['noData'], 1, 1, 'C');
            return;
        }

        foreach ($rows as $row) {
            $label = $this->formatBreakdownLabel($row['key'] ?? null, $row['label'] ?? '', $labelType, $locale);
            $pdf->Cell(85, 7, $label, 1, 0, 'C');
            $pdf->Cell(55, 7, number_format((float) ($row['amount'] ?? 0), 2) . ' ' . $labels['currency'], 1, 0, 'C');
            $pdf->Cell(0, 7, (string) ($row['count'] ?? 0), 1, 1, 'C');
        }
    }

    private function formatBreakdownLabel($key, string $fallback, string $labelType, string $locale): string
    {
        if ($labelType === 'payment') {
            $normalized = strtolower(str_replace([' ', '-'], '_', (string) ($key ?: $fallback)));
            $labels = $locale === 'ar'
                ? [
                    'cash' => 'نقدي',
                    'card' => 'بطاقة',
                    'transfer' => 'تحويل بنكي',
                    'bank_transfer' => 'تحويل بنكي',
                    'other' => 'أخرى',
                    'instapay' => 'إنستاباي',
                ]
                : [
                    'cash' => 'Cash',
                    'card' => 'Card',
                    'transfer' => 'Bank Transfer',
                    'bank_transfer' => 'Bank Transfer',
                    'other' => 'Other',
                    'instapay' => 'InstaPay',
                ];

            return $labels[$normalized] ?? $fallback;
        }

        if ($labelType === 'category') {
            $labels = $locale === 'ar'
                ? [
                    'Medical Supplies' => 'مستلزمات طبية',
                    'Equipment' => 'معدات',
                    'Services' => 'خدمات',
                    'Maintenance' => 'صيانة',
                    'Other' => 'أخرى',
                ]
                : [
                    'Medical Supplies' => 'Medical Supplies',
                    'Equipment' => 'Equipment',
                    'Services' => 'Services',
                    'Maintenance' => 'Maintenance',
                    'Other' => 'Other',
                ];

            return $labels[(string) ($key ?: $fallback)] ?? $fallback;
        }

        return $fallback;
    }

    private function resolveLocale(Request $request): string
    {
        $lang = $request->query('lang') ?: $request->header('Accept-Language', 'en');
        $lang = strtolower((string) $lang);

        return str_starts_with($lang, 'ar') ? 'ar' : 'en';
    }
}
