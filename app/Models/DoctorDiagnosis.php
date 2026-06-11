<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DoctorDiagnosis extends Model
{
    protected $fillable = [
        'doctor_id',
        'name',
        'name_en',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function doctor()
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }
}
