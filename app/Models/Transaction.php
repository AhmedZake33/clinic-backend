<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'financial_id',
        'doctor_id',
        'created_by',
        'amount',
        'payment_method',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function financial()
    {
        return $this->belongsTo(Financial::class);
    }

    public function doctor()
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
