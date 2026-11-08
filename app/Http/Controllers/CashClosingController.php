<?php

namespace App\Http\Controllers;

use App\Http\Traits\ResolvesDoctor;
use App\Models\CashClosing;
use App\Models\Purchase;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Daily cash close: the assistant counts the drawer at the end of the day and
 * the system compares it with what the day's payments say should be there.
 */
class CashClosingController extends Controller
{
    use ResolvesDoctor;

    /** Live numbers for a day (and the closing, if it was already made). */
    public function preview(Request $request)
    {
        $date = $this->dateFrom($request);
        $clinicId = $this->requireClinicId($request);

        $summary = $this->summarize($this->getDoctorIds($request), $date);
        $closing = CashClosing::with('closer:id,name')
            ->where('doctor_id', $clinicId)
            ->whereDate('closing_date', $date)
            ->first();

        return response()->json([
            'date'    => $date,
            'summary' => $summary,
            'closing' => $closing,
        ]);
    }

    public function index(Request $request)
    {
        $clinicId = $this->requireClinicId($request);

        $query = CashClosing::with('closer:id,name')
            ->where('doctor_id', $clinicId);

        if ($from = $request->query('date_from')) {
            $query->whereDate('closing_date', '>=', $from);
        }
        if ($to = $request->query('date_to')) {
            $query->whereDate('closing_date', '<=', $to);
        }

        return response()->json($query->orderByDesc('closing_date')->paginate(20));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'date'         => 'required|date_format:Y-m-d',
            'counted_cash' => 'required|numeric|min:0',
            'notes'        => 'nullable|string|max:1000',
        ]);

        if ($data['date'] > now()->toDateString()) {
            return response()->json(['message' => 'Cannot close a future day'], 422);
        }

        $clinicId = $this->requireClinicId($request);
        $doctorIds = $this->getDoctorIds($request);

        $closing = DB::transaction(function () use ($data, $clinicId, $doctorIds, $request) {
            // Serialize closings of the same clinic
            DB::table('users')->where('id', $clinicId)->lockForUpdate()->first();

            if (CashClosing::where('doctor_id', $clinicId)->whereDate('closing_date', $data['date'])->exists()) {
                return null;
            }

            $summary = $this->summarize($doctorIds, $data['date']);
            $counted = round((float) $data['counted_cash'], 2);

            return CashClosing::create([
                'doctor_id'          => $clinicId,
                'closing_date'       => $data['date'],
                'closed_by'          => $request->user()->id,
                'transactions_count' => $summary['transactions_count'],
                'cash_in'            => $summary['cash_in'],
                'cash_purchases'     => $summary['cash_purchases'],
                'expected_cash'      => $summary['expected_cash'],
                'counted_cash'       => $counted,
                'difference'         => round($counted - $summary['expected_cash'], 2),
                'totals'             => $summary,
                'notes'              => $data['notes'] ?? null,
            ]);
        });

        if (!$closing) {
            return response()->json(['message' => 'This day is already closed'], 422);
        }

        return response()->json($closing->load('closer:id,name'), 201);
    }

    /** Re-open a day (doctor only, see routes). */
    public function reopen(Request $request, CashClosing $cashClosing)
    {
        if ($cashClosing->doctor_id !== $this->requireClinicId($request)) {
            abort(403);
        }

        $cashClosing->delete();

        return response()->json(['message' => 'Day re-opened']);
    }

    private function summarize(array $doctorIds, string $date): array
    {
        $byMethod = Transaction::whereIn('doctor_id', $doctorIds)
            ->whereDate('created_at', $date)
            ->select('payment_method', DB::raw('SUM(amount) as total'), DB::raw('COUNT(*) as cnt'))
            ->groupBy('payment_method')
            ->get();

        $methods = [];
        foreach ($byMethod as $row) {
            $methods[$row->payment_method] = round((float) $row->total, 2);
        }

        $cashIn = $methods['cash'] ?? 0.0;

        $purchases = Purchase::whereIn('doctor_id', $doctorIds)
            ->whereDate('purchase_date', $date)
            ->get(['amount_paid', 'payment_method']);

        $cashPurchases = round((float) $purchases
            ->filter(fn ($p) => strtolower((string) $p->payment_method) === 'cash')
            ->sum('amount_paid'), 2);

        return [
            'transactions_count' => (int) $byMethod->sum('cnt'),
            'by_method'          => $methods,
            'total_in'           => round(array_sum($methods), 2),
            'cash_in'            => $cashIn,
            'cash_purchases'     => $cashPurchases,
            'purchases_total'    => round((float) $purchases->sum('amount_paid'), 2),
            'expected_cash'      => round($cashIn - $cashPurchases, 2),
        ];
    }

    private function dateFrom(Request $request): string
    {
        $date = (string) $request->query('date', now()->toDateString());

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : now()->toDateString();
    }

    private function requireClinicId(Request $request): int
    {
        $id = CashClosing::clinicId($request->user());

        if (!$id) {
            abort(403, 'No clinic context available.');
        }

        return $id;
    }
}
