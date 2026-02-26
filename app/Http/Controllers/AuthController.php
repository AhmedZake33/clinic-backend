<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // For assistants, verify they are assigned to a doctor
        if ($user->role === 'assistant' && !$user->doctor_id) {
            throw ValidationException::withMessages([
                'email' => ['Your account is not assigned to a doctor. Please contact your administrator.'],
            ]);
        }

        // Check subscription status for doctors
        if ($user->role === 'doctor' && $user->isSubscriptionExpired()) {
            throw ValidationException::withMessages([
                'email' => ['Your subscription has expired. Please contact the administrator.'],
            ]);
        }

        $token = $user->createToken('clinic-app')->plainTextToken;

        // Load doctor relationship for assistants
        if ($user->role === 'assistant') {
            $user->load('doctor:id,name,email');
        }

        return response()->json([
            'user' => $user,
            'token' => $token,
        ]);
    }

    public function register(Request $request)
    {
        $rules = [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users',
            'password' => 'required|min:8|confirmed',
            'role' => 'required|in:admin,doctor,assistant,client',
        ];

        // Assistants must be assigned to a doctor
        if ($request->role === 'assistant') {
            $rules['doctor_id'] = 'required|exists:users,id';
        }

        // Subscription fields for doctors
        if ($request->role === 'doctor') {
            $rules['subscription_start'] = 'nullable|date';
            $rules['subscription_end'] = 'nullable|date|after_or_equal:subscription_start';
            $rules['subscription_plan'] = 'nullable|string|max:255';
            $rules['subscription_amount'] = 'nullable|numeric|min:0';
        }

        $request->validate($rules);

        // Verify the doctor_id references an actual doctor
        if ($request->role === 'assistant') {
            $doctor = User::where('id', $request->doctor_id)->where('role', 'doctor')->first();
            if (!$doctor) {
                return response()->json(['error' => 'The selected doctor_id does not belong to a doctor'], 422);
            }
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => $request->role,
            'doctor_id' => $request->role === 'assistant' ? $request->doctor_id : null,
            'subscription_start' => $request->role === 'doctor' ? $request->subscription_start : null,
            'subscription_end' => $request->role === 'doctor' ? $request->subscription_end : null,
            'is_active' => $request->role === 'doctor' ? ($request->is_active ?? true) : true,
            'subscription_plan' => $request->role === 'doctor' ? $request->subscription_plan : null,
            'subscription_amount' => $request->role === 'doctor' ? $request->subscription_amount : null,
        ]);

        $token = $user->createToken('clinic-app')->plainTextToken;

        // Load doctor relationship for assistants
        if ($user->role === 'assistant') {
            $user->load('doctor:id,name,email');
        }

        return response()->json([
            'user' => $user,
            'token' => $token,
        ], 201);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully']);
    }

    public function me(Request $request)
    {
        $user = $request->user();

        // Load doctor relationship for assistants
        if ($user->role === 'assistant') {
            $user->load('doctor:id,name,email');
        }

        // Load assistant count for doctors
        if ($user->role === 'doctor') {
            $user->loadCount('assistants');
        }

        // Add subscription status for doctors
        if ($user->role === 'doctor') {
            $user->subscription_status = $user->getSubscriptionStatus();
        }

        return response()->json($user);
    }
}
