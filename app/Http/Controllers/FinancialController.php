<?php

namespace App\Http\Controllers;

use App\Models\Financial;
use App\Models\Reservation;
use Illuminate\Http\Request;

class FinancialController extends Controller
{
    public function index(Request $request)
    {
        $query = Financial::with(['reservation', 'client', 'doctor', 'creator']);

        // Filter by role
        if ($request->user()->role === 'doctor') {
            $query->where('doctor_id', $request->user()->id);
        }

        // Filter by doctor (for assistants)
        if ($request->user()->role !== 'doctor') {
            if ($doctorId = $request->query('doctor_id')) {
                $query->where('doctor_id', $doctorId);
            }
        }

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

        $financials = $query->latest()->paginate(10);
        return response()->json($financials);
    }

    public function store(Request $request)
    {
        $request->validate([
            'reservation_id' => 'required|exists:reservations,id',
            'amount' => 'required|numeric|min:0',
            'paid' => 'nullable|numeric|min:0',
            'payment_method' => 'required|in:cash,card,transfer,other',
            'notes' => 'nullable|string',
        ]);

        $reservation = Reservation::with('client')->findOrFail($request->reservation_id);

        $paid = $request->paid ?? 0;
        $amount = $request->amount;
        $remaining = $amount - $paid;

        // Determine payment status
        if ($paid <= 0) {
            $paymentStatus = 'unpaid';
        } elseif ($paid >= $amount) {
            $paymentStatus = 'paid';
            $remaining = 0;
            $paid = $amount;
        } else {
            $paymentStatus = 'partial';
        }

        $financial = Financial::create([
            'reservation_id' => $reservation->id,
            'client_id' => $reservation->client_id,
            'doctor_id' => $reservation->doctor_id,
            'created_by' => $request->user()->id,
            'amount' => $amount,
            'paid' => $paid,
            'remaining' => $remaining,
            'payment_status' => $paymentStatus,
            'payment_method' => $request->payment_method,
            'notes' => $request->notes,
        ]);

        $financial->load(['reservation', 'client', 'doctor', 'creator']);

        return response()->json($financial, 201);
    }

    public function show(Financial $financial)
    {
        $financial->load(['reservation', 'client', 'doctor', 'creator']);
        return response()->json($financial);
    }

    public function update(Request $request, Financial $financial)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0',
            'paid' => 'nullable|numeric|min:0',
            'payment_method' => 'required|in:cash,card,transfer,other',
            'notes' => 'nullable|string',
        ]);

        $paid = $request->paid ?? 0;
        $amount = $request->amount;
        $remaining = $amount - $paid;

        if ($paid <= 0) {
            $paymentStatus = 'unpaid';
        } elseif ($paid >= $amount) {
            $paymentStatus = 'paid';
            $remaining = 0;
            $paid = $amount;
        } else {
            $paymentStatus = 'partial';
        }

        $financial->update([
            'amount' => $amount,
            'paid' => $paid,
            'remaining' => $remaining,
            'payment_status' => $paymentStatus,
            'payment_method' => $request->payment_method,
            'notes' => $request->notes,
        ]);

        $financial->load(['reservation', 'client', 'doctor', 'creator']);

        return response()->json($financial);
    }

    public function destroy(Financial $financial)
    {
        $financial->delete();
        return response()->json(['message' => 'Financial record deleted']);
    }

    /**
     * Get summary statistics for the financial overview.
     */
    public function summary(Request $request)
    {
        $query = Financial::query();

        if ($request->user()->role === 'doctor') {
            $query->where('doctor_id', $request->user()->id);
        }

        if ($doctorId = $request->query('doctor_id')) {
            $query->where('doctor_id', $doctorId);
        }

        if ($from = $request->query('date_from')) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->query('date_to')) {
            $query->whereDate('created_at', '<=', $to);
        }

        $totalAmount = (clone $query)->sum('amount');
        $totalPaid = (clone $query)->sum('paid');
        $totalRemaining = (clone $query)->sum('remaining');
        $totalRecords = (clone $query)->count();
        $paidCount = (clone $query)->where('payment_status', 'paid')->count();
        $partialCount = (clone $query)->where('payment_status', 'partial')->count();
        $unpaidCount = (clone $query)->where('payment_status', 'unpaid')->count();

        return response()->json([
            'total_amount' => round($totalAmount, 2),
            'total_paid' => round($totalPaid, 2),
            'total_remaining' => round($totalRemaining, 2),
            'total_records' => $totalRecords,
            'paid_count' => $paidCount,
            'partial_count' => $partialCount,
            'unpaid_count' => $unpaidCount,
        ]);
    }
}
