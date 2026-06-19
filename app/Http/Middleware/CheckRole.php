<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        if (!$request->user()) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $user = $request->user();

        // Check using Spatie roles first, fallback to legacy role column
        $hasRole = false;
        foreach ($roles as $role) {
            if ($user->hasRole($role) || $user->role === $role) {
                $hasRole = true;
                break;
            }
        }

        if (!$hasRole) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Additional check: ensure doctor's subscription is active
        if ($user->role === 'doctor' && $user->isSubscriptionExpired()) {
            return response()->json([
                'error' => User::SUBSCRIPTION_EXPIRED_MESSAGE,
                'code' => 'subscription_expired',
            ], 403);
        }

        // Block assistants whose doctor's subscription is expired
        if ($user->role === 'assistant' && $user->doctor_id) {
            $doctor = User::find($user->doctor_id);
            if ($doctor && $doctor->isSubscriptionExpired()) {
                return response()->json([
                    'error' => User::SUBSCRIPTION_EXPIRED_MESSAGE,
                    'code' => 'subscription_expired',
                ], 403);
            }
        }

        // Block sub-doctors whose parent doctor's subscription is expired
        if ($user->role === 'sub-doctor' && $user->parent_doctor_id) {
            $doctor = User::find($user->parent_doctor_id);
            if ($doctor && $doctor->isSubscriptionExpired()) {
                return response()->json([
                    'error' => User::SUBSCRIPTION_EXPIRED_MESSAGE,
                    'code' => 'subscription_expired',
                ], 403);
            }
        }

        return $next($request);
    }
}
