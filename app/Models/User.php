<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasApiTokens, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'doctor_id',
        'subscription_start',
        'subscription_end',
        'is_active',
        'subscription_plan',
        'subscription_amount',
        'notes',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'subscription_start' => 'date',
            'subscription_end' => 'date',
            'is_active' => 'boolean',
            'subscription_amount' => 'decimal:2',
        ];
    }

    /**
     * The doctor this assistant belongs to.
     */
    public function doctor()
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    /**
     * Assistants belonging to this doctor.
     */
    public function assistants()
    {
        return $this->hasMany(User::class, 'doctor_id');
    }

    /**
     * Clients belonging to this doctor.
     */
    public function clients()
    {
        return $this->hasMany(Client::class, 'doctor_id');
    }

    /**
     * Reservations for this doctor.
     */
    public function reservations()
    {
        return $this->hasMany(Reservation::class, 'doctor_id');
    }

    /**
     * Check if user is admin.
     */
    public function isAdmin()
    {
        return $this->role === 'admin';
    }

    /**
     * Check if user is doctor.
     */
    public function isDoctor()
    {
        return $this->role === 'doctor';
    }

    /**
     * Check if user is assistant.
     */
    public function isAssistant()
    {
        return $this->role === 'assistant';
    }

    /**
     * Check if user is client.
     */
    public function isClient()
    {
        return $this->role === 'client';
    }

    /**
     * Check if subscription is active.
     */
    public function isSubscriptionActive()
    {
        if ($this->isAdmin()) {
            return true; // Admins always have active subscription
        }

        return $this->is_active &&
               $this->subscription_end &&
               now()->lte($this->subscription_end);
    }

    /**
     * Check if subscription is expired.
     */
    public function isSubscriptionExpired()
    {
        if ($this->isAdmin()) {
            return false; // Admins never expire
        }

        return !$this->is_active ||
               ($this->subscription_end && now()->gt($this->subscription_end));
    }

    /**
     * Get subscription status.
     */
    public function getSubscriptionStatus()
    {
        if ($this->isAdmin()) {
            return 'active';
        }

        if (!$this->is_active) {
            return 'inactive';
        }

        if (!$this->subscription_end) {
            return 'no_subscription';
        }

        return now()->lte($this->subscription_end) ? 'active' : 'expired';
    }
}
