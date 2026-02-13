<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DoctorHoliday extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'date',
        'recurring_day_of_week',
        'reason',
    ];

    protected $casts = [
        'date' => 'date',
        'recurring_day_of_week' => 'integer',
    ];

    public function doctor()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
