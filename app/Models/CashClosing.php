<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashClosing extends Model
{
    protected $fillable = [
        'doctor_id',
        'closing_date',
        'closed_by',
        'transactions_count',
        'cash_in',
        'cash_purchases',
        'expected_cash',
        'counted_cash',
        'difference',
        'totals',
        'notes',
    ];

    protected $casts = [
        'closing_date'   => 'date:Y-m-d',
        'cash_in'        => 'decimal:2',
        'cash_purchases' => 'decimal:2',
        'expected_cash'  => 'decimal:2',
        'counted_cash'   => 'decimal:2',
        'difference'     => 'decimal:2',
        'totals'         => 'array',
    ];

    public function closer()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /** The clinic owner id for any clinic user (sub-doctors belong to their parent doctor). */
    public static function clinicId(User $user): ?int
    {
        return match ($user->role) {
            'doctor'     => $user->id,
            'assistant'  => $user->doctor_id,
            'sub-doctor' => $user->parent_doctor_id,
            default      => null,
        };
    }

    public static function isClosed(?int $clinicId, string $date): bool
    {
        return $clinicId !== null
            && static::where('doctor_id', $clinicId)->whereDate('closing_date', $date)->exists();
    }
}
