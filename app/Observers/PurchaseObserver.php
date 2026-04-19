<?php

namespace App\Observers;

use App\Models\Financial;
use App\Models\Purchase;
use Illuminate\Support\Facades\Auth;

class PurchaseObserver
{
    /**
     * Handle the Purchase "created" event.
     */
    public function created(Purchase $purchase): void
    {
        $userId = $purchase->created_by ?? (Auth::check() ? Auth::id() : null);

        // Financial::create([
        //     'reservation_id' => null,
        //     'client_id' => null,
        //     'doctor_id' => null,
        //     'created_by' => $userId,
        //     'amount' => $purchase->amount_paid,
        //     'paid' => $purchase->amount_paid,
        //     'remaining' => 0,
        //     'payment_status' => $purchase->amount_paid > 0 ? 'paid' : 'unpaid',
        //     'payment_method' => $purchase->payment_method,
        //     'notes' => trim('Purchase: ' . $purchase->item_name . '. ' . ($purchase->notes ?? '')),
        // ]);
    }
}
