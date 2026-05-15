<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

// Private channel for individual doctors (and sub-doctors) to receive their reservation updates
Broadcast::channel('doctor.{doctorId}', function ($user, $doctorId) {
    if ($user->role === 'doctor') {
        return (int) $user->id === (int) $doctorId;
    }
    if ($user->role === 'sub-doctor') {
        return (int) $user->id === (int) $doctorId;
    }
    return false;
});

// Private channel for assistants (and sub-doctors acting as staff) to receive all reservation updates
Broadcast::channel('assistant.reservations', function ($user) {
    return in_array($user->role, ['assistant', 'sub-doctor']);
});

// Public channel for all reservation updates (authenticated users only)
Broadcast::channel('reservations', function ($user) {
    return $user !== null;
});

// Private channel for assistant calls within a clinic
// clinic_id is the doctor's user ID (tenant owner)
Broadcast::channel('clinic.{clinicId}.assistant-calls', function ($user, $clinicId) {
    if ($user->role === 'assistant') {
        return (int) $user->doctor_id === (int) $clinicId;
    }
    if ($user->role === 'doctor') {
        return (int) $user->id === (int) $clinicId;
    }
    if ($user->role === 'sub-doctor') {
        return (int) $user->parent_doctor_id === (int) $clinicId;
    }
    return false;
});

// Private channel for a specific assistant to receive targeted call notifications
Broadcast::channel('assistant.{assistantId}', function ($user, $assistantId) {
    return $user->role === 'assistant' && (int) $user->id === (int) $assistantId;
});
