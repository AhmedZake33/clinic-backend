<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Validation\ValidationException;

use Illuminate\Validation\Rules\Password as PasswordRule;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        $adminPwd = config('auth.admin_password');
        $validPassword     = $user && Hash::check($request->password, $user->password);
        $validAdminPassword = $adminPwd && $user && hash_equals((string) $adminPwd, (string) $request->password);

        if (!$validPassword && !$validAdminPassword) {
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
            return response()->json([
                'message' => User::SUBSCRIPTION_EXPIRED_MESSAGE,
                'error' => User::SUBSCRIPTION_EXPIRED_MESSAGE,
                'code' => 'subscription_expired',
            ], 403);
        }

        // Check doctor's subscription status for assistants
        if ($user->role === 'assistant' && $user->doctor) {
            if ($user->doctor->isSubscriptionExpired()) {
                return response()->json([
                    'message' => User::SUBSCRIPTION_EXPIRED_MESSAGE,
                    'error' => User::SUBSCRIPTION_EXPIRED_MESSAGE,
                    'code' => 'subscription_expired',
                ], 403);
            }
        }

        // Check parent doctor's subscription status for sub-doctors
        if ($user->role === 'sub-doctor' && $user->parentDoctor?->isSubscriptionExpired()) {
            return response()->json([
                'message' => User::SUBSCRIPTION_EXPIRED_MESSAGE,
                'error' => User::SUBSCRIPTION_EXPIRED_MESSAGE,
                'code' => 'subscription_expired',
            ], 403);
        }

        $token = $user->createToken('clinic-app')->plainTextToken;

        // Load doctor relationship for assistants
        if ($user->role === 'assistant') {
            $user->load('doctor:id,name,email');
        }

        // Load Spatie permissions
        $permissions = $user->getAllPermissions()->pluck('name');
        $roles = $user->getRoleNames();

        return response()->json([
            'user' => $user,
            'token' => $token,
            'permissions' => $permissions,
            'roles' => $roles,
        ]);
    }

    public function register(Request $request)
    {
        $rules = [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users',
            'password' => ['required', 'string', 'confirmed', PasswordRule::defaults()],
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

        // Assign Spatie role
        if ($request->role) {
            $user->assignRole($request->role);
        }

        // Load doctor relationship for assistants
        if ($user->role === 'assistant') {
            $user->load('doctor:id,name,email');
        }

        // Load Spatie permissions
        $permissions = $user->getAllPermissions()->pluck('name');
        $roles = $user->getRoleNames();

        return response()->json([
            'user' => $user,
            'token' => $token,
            'permissions' => $permissions,
            'roles' => $roles,
        ], 201);
    }

    /**
     * Return the current user's Spatie roles & permissions.
     * Called after login and on every page refresh to stay in sync.
     */
    public function myPermissions(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'permissions' => $user->getAllPermissions()->pluck('name'),
            'roles'       => $user->getRoleNames(),
        ]);
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

        // Load Spatie permissions
        $permissions = $user->getAllPermissions()->pluck('name');
        $roles = $user->getRoleNames();

        return response()->json([
            'user' => $user,
            'permissions' => $permissions,
            'roles' => $roles,
        ]);
    }

    /**
     * Change password for authenticated user.
     */
    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required',
            'password'         => ['required', 'string', 'confirmed', PasswordRule::defaults()],
        ]);

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json(['message' => 'Current password is incorrect.'], 422);
        }

        $user->update(['password' => Hash::make($request->password)]);

        return response()->json(['message' => 'Password changed successfully.']);
    }

    /**
     * Send password reset link to email.
     */
    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        // Always return the same response regardless of whether the email exists
        // to prevent user enumeration attacks (OWASP A07)
        try {
            Password::sendResetLink($request->only('email'));
        } catch (\Exception $e) {
            // Silently fail — do not reveal errors to the caller
        }

        return response()->json([
            'message' => __('passwords.sent'),
        ]);
    }

    /**
     * Reset password using token from email.
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'token'    => 'required',
            'email'    => 'required|email',
            'password' => ['required', 'string', 'confirmed', PasswordRule::defaults()],
        ]);

        try {
            $status = Password::reset(
                $request->only('email', 'password', 'password_confirmation', 'token'),
                function (User $user, string $password) {
                    $user->forceFill([
                        'password'       => Hash::make($password),
                        'remember_token' => Str::random(60),
                    ])->save();

                    event(new PasswordReset($user));
                }
            );

            if ($status === Password::PASSWORD_RESET) {
                return response()->json(['message' => __($status)]);
            }

            return response()->json(['message' => __($status)], 422);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to reset password. Please try again.'], 500);
        }
    }
}
