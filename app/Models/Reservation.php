<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Reservation extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'doctor_id',
        'created_by',
        'appointment_date',
        'status',
        'notes',
        'diagnosis',
        'treatment',
        'completed_at',
    ];

    protected $casts = [
        'appointment_date' => 'datetime',
        'completed_at' => 'datetime',
    ];

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

    public function financial()
    {
        return $this->hasOne(Financial::class);
    }
}
