<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DoctorService extends Model
{
    protected $fillable = [
        'doctor_id', 'name', 'name_en', 'description', 'price', 'is_active',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function doctor()
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    public function reservationServices()
    {
        return $this->hasMany(ReservationService::class);
    }
}
