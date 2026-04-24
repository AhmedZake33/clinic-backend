<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    use HasFactory;

    public static function chronicIllnessOptions(): array
    {
        return array_keys(config('client.chronic_illnesses', []));
    }

    public static function bloodTypeOptions(): array
    {
        return ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
    }

    protected $fillable = [
        'name',
        'email',
        'phone',
        'phone_country_code',
        'whatsapp_number',
        'whatsapp_country_code',
        'date_of_birth',
        'height',
        'weight',
        'address',
        'job',
        'blood_type',
        'medical_history',
        'chronic_illnesses',
        'created_by',
        'doctor_id',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'chronic_illnesses' => 'array',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function doctor()
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    public function reservations()
    {
        return $this->hasMany(Reservation::class);
    }
}
