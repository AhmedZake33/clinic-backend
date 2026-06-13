<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckClinicPermission
{
    public function handle(Request $request, Closure $next, ...$permissions): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        if ($user->role === 'assistant') {
            $directPermissions = $user->getDirectPermissions()->pluck('name')->all();

            foreach ($permissions as $permission) {
                if (in_array($permission, $directPermissions, true)) {
                    return $next($request);
                }
            }

            return response()->json(['error' => 'Unauthorized'], 403);
        }

        return $next($request);
    }
}
