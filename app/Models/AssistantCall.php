<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AssistantCall extends Model
{
    use HasFactory;

    protected $fillable = [
        'doctor_id',
        'assistant_id',
        'clinic_id',
        'status',
        'message',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * The doctor who initiated the call.
     */
    public function doctor()
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    /**
     * The assistant who accepted the call.
     */
    public function assistant()
    {
        return $this->belongsTo(User::class, 'assistant_id');
    }

    /**
     * Scope to active (non-done) calls.
     */
    public function scopeActive($query)
    {
        return $query->whereIn('status', ['pending', 'accepted']);
    }

    /**
     * Scope to calls for a specific clinic.
     */
    public function scopeForClinic($query, $clinicId)
    {
        return $query->where('clinic_id', $clinicId);
    }
}
