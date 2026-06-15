<?php

namespace App\Http\Controllers;

use App\Http\Traits\ResolvesDoctor;
use App\Models\Financial;
use App\Models\Reservation;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FinancialController extends Controller
{
    use ResolvesDoctor;

    public function index(Request $request)
    {
        $doctorIds = $this->getDoctorIds($request);
        // return response()->json($doctorIds);
        // return response()->json($doctorIds);
        $query = Financial::with(['reservation', 'client', 'doctor', 'creator'])
            ->whereIn('doctor_id', $doctorIds)
            ->where('voided', false);

        // Filter by payment status
        if ($status = $request->query('payment_status')) {
            $query->where('payment_status', $status);
        }

        // Filter by payment method
        if ($method = $request->query('payment_method')) {
            $query->where('payment_method', $method);
        }

        // Date range
        if ($from = $request->query('date_from')) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->query('date_to')) {
            $query->whereDate('created_at', '<=', $to);
        }

        // Search by client name
        if ($search = $request->query('search')) {
            $query->whereHas('client', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
            });
        }
        // return $query->toRawSql();
        //  $
        $financials = $query->latest()->paginate(10);
        return response()->json($financials);
    }

    public function store(Request $request)
    {
        $doctorIds = $this->getDoctorIds($request);

        $request->validate([
            'reservation_id' => 'required|exists:reservations,id',
            'amount' => 'required|numeric|min:0',
            'paid' => 'nullable|numeric|min:0',
            'payment_method' => 'required|in:cash,card,transfer,other',
            'notes' => 'nullable|string',
        ]);

        $reservation = Reservation::with('client')
            ->whereIn('doctor_id', $doctorIds)
            ->findOrFail($request->reservation_id);

        $amount = round((float) $request->amount, 2);
        $paid = round((float) ($request->paid ?? 0), 2);
        [$paid, $remaining, $paymentStatus] = $this->paymentTotalsForAmount($amount, $paid);

        $financial = DB::transaction(function () use ($request, $reservation, $paid, $amount, $remaining, $paymentStatus) {
            $financial = Financial::create([
                'reservation_id' => $reservation->id,
                'client_id'      => $reservation->client_id,
                'doctor_id'      => $reservation->doctor_id,
                'created_by'     => $request->user()->id,
                'amount'         => $amount,
                'paid'           => $paid,
                'remaining'      => $remaining,
                'payment_status' => $paymentStatus,
                'payment_method' => $request->payment_method,
                'notes'          => $request->notes,
            ]);

            if ($paid > 0) {
                Transaction::create([
                    'financial_id'   => $financial->id,
                    'doctor_id'      => $financial->doctor_id,
                    'created_by'     => $request->user()->id,
                    'amount'         => $paid,
                    'payment_method' => $request->payment_method,
                    'notes'          => null,
                ]);
            }

            return $financial;
        });

        $financial->load(['reservation', 'client', 'doctor', 'creator']);

        return response()->json($financial, 201);
    }

    public function show(Request $request, Financial $financial)
    {
        $doctorIds = $this->getDoctorIds($request);

        if (!in_array($financial->doctor_id, $doctorIds)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $financial->load(['reservation', 'client', 'doctor', 'creator']);
        return response()->json($financial);
    }

    public function update(Request $request, Financial $financial)
    {
        $doctorIds = $this->getDoctorIds($request);

        if (!in_array($financial->doctor_id, $doctorIds)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $request->validate([
            'amount' => 'required|numeric|min:0',
            'paid' => 'nullable|numeric|min:0',
            'payment_method' => 'required|in:cash,card,transfer,other',
            'notes' => 'nullable|string',
        ]);

        $amount = round((float) $request->amount, 2);

        DB::transaction(function () use ($request, $financial, $amount) {
            $transactionsPaid = round((float) $financial->transactions()->sum('amount'), 2);
            $hasTransactions = $financial->transactions()->exists();
            $paidSource = $hasTransactions
                ? $transactionsPaid
                : round((float) ($request->paid ?? $financial->paid ?? 0), 2);
            [$paid, $remaining, $paymentStatus] = $this->paymentTotalsForAmount($amount, $paidSource);

            $financial->update([
                'amount'         => $amount,
                'paid'           => $paid,
                'remaining'      => $remaining,
                'payment_status' => $paymentStatus,
                'payment_method' => $request->payment_method,
                'notes'          => $request->notes,
            ]);
        });

        $financial->load(['reservation', 'client', 'doctor', 'creator']);

        return response()->json($financial);
    }

    private function paymentTotalsForAmount(float $amount, float $paidSource): array
    {
        $amount = max(0, round($amount, 2));
        $paid = min(max(0, round($paidSource, 2)), $amount);
        $remaining = max(0, round($amount - $paid, 2));

        if ($paid <= 0) {
            $paymentStatus = 'unpaid';
        } elseif ($remaining <= 0) {
            $paymentStatus = 'paid';
            $remaining = 0;
        } else {
            $paymentStatus = 'partial';
        }

        return [$paid, $remaining, $paymentStatus];
    }

    public function destroy(Request $request, Financial $financial)
    {
        $doctorIds = $this->getDoctorIds($request);

        if (!in_array($financial->doctor_id, $doctorIds)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $financial->delete();
        return response()->json(['message' => 'Financial record deleted']);
    }

    /**
     * Get summary statistics for the financial overview.
     */
    public function summary(Request $request)
    {
        $doctorIds = $this->getDoctorIds($request);
        $from = $request->query('date_from');
        $to   = $request->query('date_to');

        $applyFilters = function ($q) use ($from, $to) {
            if ($from) $q->whereDate('created_at', '>=', $from);
            if ($to)   $q->whereDate('created_at', '<=', $to);
        };

        $query = Financial::query()->whereIn('doctor_id', $doctorIds)->where('voided', false);
        $applyFilters($query);

        $totalAmount    = (clone $query)->sum('amount');
        $totalPaid      = (clone $query)->sum('paid');
        $totalRemaining = (clone $query)->sum('remaining');
        $totalRecords   = (clone $query)->count();
        $paidCount      = (clone $query)->where('payment_status', 'paid')->count();
        $partialCount   = (clone $query)->where('payment_status', 'partial')->count();
        $unpaidCount    = (clone $query)->where('payment_status', 'unpaid')->count();

        // Per-doctor breakdown
        $doctors = \App\Models\User::whereIn('id', $doctorIds)
            ->select('id', 'name', 'role')
            ->get()
            ->keyBy('id');

        $byDoctor = [];
        foreach ($doctorIds as $doctorId) {
            $dq = Financial::query()->where('doctor_id', $doctorId)->where('voided', false);
            $applyFilters($dq);

            $doctor = $doctors->get($doctorId);
            $byDoctor[] = [
                'doctor_id'      => $doctorId,
                'doctor_name'    => $doctor?->name ?? "Doctor #{$doctorId}",
                'doctor_role'    => $doctor?->role ?? 'doctor',
                'total_amount'   => round((clone $dq)->sum('amount'), 2),
                'total_paid'     => round((clone $dq)->sum('paid'), 2),
                'total_remaining'=> round((clone $dq)->sum('remaining'), 2),
                'total_records'  => (clone $dq)->count(),
                'paid_count'     => (clone $dq)->where('payment_status', 'paid')->count(),
                'partial_count'  => (clone $dq)->where('payment_status', 'partial')->count(),
                'unpaid_count'   => (clone $dq)->where('payment_status', 'unpaid')->count(),
            ];
        }

        return response()->json([
            'total_amount'   => round($totalAmount, 2),
            'total_paid'     => round($totalPaid, 2),
            'total_remaining'=> round($totalRemaining, 2),
            'total_records'  => $totalRecords,
            'paid_count'     => $paidCount,
            'partial_count'  => $partialCount,
            'unpaid_count'   => $unpaidCount,
            'by_doctor'      => $byDoctor,
        ]);
    }
}
