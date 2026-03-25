<?php

namespace App\Http\Middleware;

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
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Additional check: ensure doctor's subscription is active
        if ($user->role === 'doctor' && $user->isSubscriptionExpired()) {
            return response()->json(['error' => 'Subscription expired. Please contact administrator.'], 403);
        }

        // Block assistants whose doctor's subscription is expired
        if ($user->role === 'assistant' && $user->doctor_id) {
            $doctor = \App\Models\User::find($user->doctor_id);
            if ($doctor && $doctor->isSubscriptionExpired()) {
                return response()->json(['error' => 'The doctor\'s subscription has expired. Please contact the administrator.'], 403);
            }
        }

        return $next($request);
    }
}
