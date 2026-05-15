<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Financial extends Model
{
    use HasFactory;

    protected $fillable = [
        'reservation_id',
        'client_id',
        'doctor_id',
        'created_by',
        'amount',
        'paid',
        'remaining',
        'payment_status',
        'payment_method',
        'notes',
        'reservation_service_id',
        'voided',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid' => 'decimal:2',
        'remaining' => 'decimal:2',
        'voided' => 'boolean',
    ];

    public function reservationService()
    {
        return $this->belongsTo(ReservationService::class, 'reservation_service_id');
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
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
