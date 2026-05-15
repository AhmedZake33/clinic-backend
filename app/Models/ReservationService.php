<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReservationService extends Model
{
    protected $fillable = [
        'reservation_id', 'doctor_service_id', 'doctor_id', 'client_id',
        'created_by', 'service_name', 'quantity', 'unit_price', 'total_price',
        'with_invoice', 'notes',
    ];

    protected $casts = [
        'unit_price'   => 'decimal:2',
        'total_price'  => 'decimal:2',
        'with_invoice' => 'boolean',
    ];

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    public function doctorService()
    {
        return $this->belongsTo(DoctorService::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
