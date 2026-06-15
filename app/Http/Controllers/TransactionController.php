<?php

namespace App\Http\Controllers;

use App\Http\Traits\ResolvesDoctor;
use App\Models\Financial;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TransactionController extends Controller
{
    use ResolvesDoctor;

    /** List all transactions for a financial record */
    public function index(Request $request, Financial $financial)
    {
        $doctorIds = $this->getDoctorIds($request);

        if (!in_array($financial->doctor_id, $doctorIds)) {
            abort(403);
        }

        $transactions = $financial->transactions()->with('creator')->latest()->get();

        return response()->json($transactions);
    }

    /** List all transactions across all financials for the doctor */
    public function allForDoctor(Request $request)
    {
        $doctorIds = $this->getDoctorIds($request);

        $query = Transaction::with(['financial.client', 'creator'])
            ->whereIn('doctor_id', $doctorIds);

        if ($method = $request->query('payment_method')) {
            $query->where('payment_method', $method);
        }

        if ($search = $request->query('search')) {
            $clientIdSearch = $this->clientIdSearchTerm($search);

            $query->whereHas('financial.client', function ($q) use ($search, $clientIdSearch) {
                if ($this->isStrictClientIdSearch($search)) {
                    $q->where('id', $clientIdSearch ?? 0);
                    return;
                }

                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('whatsapp_number', 'like', "%{$search}%");

                if ($clientIdSearch !== null) {
                    $q->orWhere('id', $clientIdSearch);
                }
            });
        }

        if ($from = $request->query('date_from')) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->query('date_to')) {
            $query->whereDate('created_at', '<=', $to);
        }

        $transactions = $query->latest()->paginate(20);

        return response()->json($transactions);
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

    /** Create a new payment transaction for a financial record */
    public function store(Request $request, Financial $financial)
    {
        $doctorIds = $this->getDoctorIds($request);

        if (!in_array($financial->doctor_id, $doctorIds)) {
            abort(403);
        }

        $doctorId = $financial->doctor_id;

        $request->validate([
            'amount'         => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:cash,card,transfer,other,instapay',
            'notes'          => 'nullable|string|max:500',
        ]);

        $txAmount = (float) $request->amount;
        $maxAllowed = (float) $financial->remaining;

        if ($txAmount > $maxAllowed) {
            return response()->json([
                'message' => __('transaction.exceedsRemaining', ['remaining' => $maxAllowed]),
            ], 422);
        }

        DB::transaction(function () use ($request, $financial, $doctorId, $txAmount) {
            $tx = Transaction::create([
                'financial_id'   => $financial->id,
                'doctor_id'      => $doctorId,
                'created_by'     => $request->user()->id,
                'amount'         => $txAmount,
                'payment_method' => $request->payment_method,
                'notes'          => $request->notes,
            ]);

            // Recalculate paid/remaining from all transactions
            $totalPaid = $financial->transactions()->sum('amount') + 0; // already saved above
            $paid      = min($totalPaid, (float) $financial->amount);
            $remaining = (float) $financial->amount - $paid;

            if ($paid <= 0) {
                $status = 'unpaid';
            } elseif ($remaining <= 0) {
                $status = 'paid';
                $remaining = 0;
            } else {
                $status = 'partial';
            }

            $financial->update([
                'paid'           => $paid,
                'remaining'      => $remaining,
                'payment_status' => $status,
                'payment_method' => $request->payment_method,
            ]);
        });

        $financial->refresh();
        $financial->load(['reservation', 'client', 'doctor', 'creator', 'transactions.creator']);

        return response()->json($financial, 201);
    }

    /** Create multiple payment transactions at once */
    public function storeBatch(Request $request, Financial $financial)
    {
        $doctorIds = $this->getDoctorIds($request);

        if (!in_array($financial->doctor_id, $doctorIds)) {
            abort(403);
        }

        $doctorId = $financial->doctor_id;

        $request->validate([
            'transactions'                   => 'required|array|min:1',
            'transactions.*.amount'          => 'required|numeric|min:0.01',
            'transactions.*.payment_method'  => 'required|in:cash,card,transfer,other,instapay',
            'transactions.*.notes'           => 'nullable|string|max:500',
        ]);

        $totalToAdd = collect($request->transactions)->sum('amount');
        $maxAllowed = (float) $financial->remaining;

        if (round($totalToAdd, 2) > round($maxAllowed, 2)) {
            return response()->json([
                'message' => __('transaction.exceedsRemaining', ['remaining' => $maxAllowed]),
            ], 422);
        }

        DB::transaction(function () use ($request, $financial, $doctorId) {
            foreach ($request->transactions as $txData) {
                Transaction::create([
                    'financial_id'   => $financial->id,
                    'doctor_id'      => $doctorId,
                    'created_by'     => $request->user()->id,
                    'amount'         => $txData['amount'],
                    'payment_method' => $txData['payment_method'],
                    'notes'          => $txData['notes'] ?? null,
                ]);
            }

            $totalPaid = $financial->transactions()->sum('amount');
            $paid      = min($totalPaid, (float) $financial->amount);
            $remaining = (float) $financial->amount - $paid;

            if ($paid <= 0) {
                $status = 'unpaid';
            } elseif ($remaining <= 0) {
                $status = 'paid';
                $remaining = 0;
            } else {
                $status = 'partial';
            }

            $lastMethod = collect($request->transactions)->last()['payment_method'];

            $financial->update([
                'paid'           => $paid,
                'remaining'      => $remaining,
                'payment_status' => $status,
                'payment_method' => $lastMethod,
            ]);
        });

        $financial->refresh();
        $financial->load(['reservation', 'client', 'doctor', 'creator', 'transactions.creator']);

        return response()->json($financial, 201);
    }

    /** Delete a transaction and recalculate the financial record */
    public function destroy(Request $request, Financial $financial, Transaction $transaction)
    {
        $doctorIds = $this->getDoctorIds($request);

        if (!in_array($financial->doctor_id, $doctorIds) || $transaction->financial_id !== $financial->id) {
            abort(403);
        }

        DB::transaction(function () use ($financial, $transaction) {
            $transaction->delete();

            $totalPaid = $financial->transactions()->sum('amount');
            $paid      = min($totalPaid, (float) $financial->amount);
            $remaining = (float) $financial->amount - $paid;

            if ($paid <= 0) {
                $status = 'unpaid';
            } elseif ($remaining <= 0) {
                $status = 'paid';
                $remaining = 0;
            } else {
                $status = 'partial';
            }

            $financial->update([
                'paid'           => $paid,
                'remaining'      => $remaining,
                'payment_status' => $status,
            ]);
        });

        $financial->refresh();
        $financial->load(['reservation', 'client', 'doctor', 'creator', 'transactions.creator']);

        return response()->json($financial);
    }
}
