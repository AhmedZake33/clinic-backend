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

// Private channel for individual doctors to receive their reservation updates
Broadcast::channel('doctor.{doctorId}', function ($user, $doctorId) {
    return $user->role === 'doctor' && (int) $user->id === (int) $doctorId;
});

// Private channel for assistants to receive all reservation updates
Broadcast::channel('assistant.reservations', function ($user) {
    return $user->role === 'assistant';
});

// Public channel for all reservation updates (authenticated users only)
Broadcast::channel('reservations', function ($user) {
    return $user !== null;
});
