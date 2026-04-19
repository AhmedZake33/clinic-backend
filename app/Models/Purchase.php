<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\User;

class Purchase extends Model
{
    use HasFactory;

    protected $fillable = [
        'item_name',
        'category',
        'doctor_id',
        'quantity',
        'amount_paid',
        'supplier',
        'payment_method',
        'purchase_date',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'amount_paid' => 'decimal:2',
        'purchase_date' => 'date',
    ];

    /**
     * The doctor (user) related to this purchase.
     */
    public function doctor()
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }
}
