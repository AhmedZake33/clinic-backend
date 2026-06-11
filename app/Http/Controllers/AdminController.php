<?php

namespace App\Http\Controllers;

use App\Http\Traits\NormalizesPhoneNumbers;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    use NormalizesPhoneNumbers;

    /**
     * Get all doctors with subscription info.
     */
    public function indexDoctors()
    {
        abort_unless(auth()->user()->hasRole('admin'), 401, 'Unauthorized');
        $doctors = User::where('role', 'doctor')
            ->withCount('assistants', 'clients', 'reservations')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($doctor) {
                return [
                    'id' => $doctor->id,
                    'name' => $doctor->name,
                    'email' => $doctor->email,
                    'phone' => $doctor->phone,
                    'whatsapp_number' => $doctor->whatsapp_number,
                    'specialization' => $doctor->specialization,
                    'subscription_start' => $doctor->subscription_start,
                    'subscription_end' => $doctor->subscription_end,
                    'is_active' => $doctor->is_active,
                    'subscription_plan' => $doctor->subscription_plan,
                    'subscription_amount' => $doctor->subscription_amount,
                    'subscription_status' => $doctor->getSubscriptionStatus(),
                    'assistants_count' => $doctor->assistants_count,
                    'clients_count' => $doctor->clients_count,
                    'reservations_count' => $doctor->reservations_count,
                    'notes' => $doctor->notes,
                    'max_sub_doctors' => $doctor->max_sub_doctors ?? 0,
                    'sub_doctors_count' => $doctor->subDoctors()->count(),
                    'created_at' => $doctor->created_at,
                ];
            });

        return response()->json($doctors);
    }

    /**
     * Create a new doctor.
     */
    public function storeDoctor(Request $request)
    {
        $this->normalizePhoneInputs($request);

        $validators = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'phone' => 'nullable|string|max:20',
            'whatsapp_number' => 'nullable|string|max:20',
            'specialization' => 'nullable|string|max:255',
            'password' => 'required|string|min:8',
            'subscription_start' => 'nullable|date',
            'subscription_end' => 'nullable|date|after_or_equal:subscription_start',
            'is_active' => 'boolean',
            'subscription_plan' => 'nullable|string|max:255',
            'subscription_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'max_sub_doctors' => 'nullable|integer|min:0|max:255',
        ]);

        if ($validators->fails()) {
            return response()->json(['errors' => $validators->errors()], 422);
        }

        $doctor = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'whatsapp_number' => $request->whatsapp_number,
            'specialization' => $request->specialization,
            'password' => Hash::make($request->password),
            'role' => 'doctor',
            'subscription_start' => $request->subscription_start,
            'subscription_end' => $request->subscription_end,
            'is_active' => $request->is_active ?? true,
            'subscription_plan' => $request->subscription_plan,
            'subscription_amount' => $request->subscription_amount,
            'notes' => $request->notes,
            'max_sub_doctors' => $request->max_sub_doctors ?? 0,
        ]);

        $doctor->assignRole('doctor');

        return response()->json([
            'message' => 'Doctor created successfully',
            'doctor' => $doctor
        ], 201);
    }

    /**
     * Get a specific doctor.
     */
    public function showDoctor(User $doctor)
    {
        // Ensure it's a doctor
        if ($doctor->role !== 'doctor') {
            return response()->json(['error' => 'User is not a doctor'], 404);
        }

        $doctor->load(['assistants', 'clients', 'reservations']);

        return response()->json([
            'id' => $doctor->id,
            'name' => $doctor->name,
            'email' => $doctor->email,
            'phone' => $doctor->phone,
            'whatsapp_number' => $doctor->whatsapp_number,
            'specialization' => $doctor->specialization,
            'subscription_start' => $doctor->subscription_start,
            'subscription_end' => $doctor->subscription_end,
            'is_active' => $doctor->is_active,
            'subscription_plan' => $doctor->subscription_plan,
            'subscription_amount' => $doctor->subscription_amount,
            'subscription_status' => $doctor->getSubscriptionStatus(),
            'notes' => $doctor->notes,
            'max_sub_doctors' => $doctor->max_sub_doctors ?? 0,
            'sub_doctors_count' => $doctor->subDoctors->count(),
            'assistants' => $doctor->assistants->map(function ($assistant) {
                return [
                    'id' => $assistant->id,
                    'name' => $assistant->name,
                    'email' => $assistant->email,
                    'phone' => $assistant->phone,
                    'whatsapp_number' => $assistant->whatsapp_number,
                    'created_at' => $assistant->created_at,
                ];
            }),
            'stats' => [
                'clients_count' => $doctor->clients->count(),
                'reservations_count' => $doctor->reservations->count(),
                'assistants_count' => $doctor->assistants->count(),
            ],
            'created_at' => $doctor->created_at,
        ]);
    }

    /**
     * Update a doctor.
     */
    public function updateDoctor(Request $request, User $doctor)
    {
        // Ensure it's a doctor
        if ($doctor->role !== 'doctor') {
            return response()->json(['error' => 'User is not a doctor'], 404);
        }

        $this->normalizePhoneInputs($request);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'email' => ['sometimes', 'required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($doctor->id)],
            'phone' => 'nullable|string|max:20',
            'whatsapp_number' => 'nullable|string|max:20',
            'specialization' => 'nullable|string|max:255',
            'password' => 'nullable|string|min:8',
            'subscription_start' => 'nullable|date',
            'subscription_end' => 'nullable|date|after_or_equal:subscription_start',
            'is_active' => 'boolean',
            'subscription_plan' => 'nullable|string|max:255',
            'subscription_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'max_sub_doctors' => 'nullable|integer|min:0|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $updateData = $request->only([
            'name', 'email', 'phone', 'whatsapp_number', 'specialization', 'subscription_start', 'subscription_end',
            'is_active', 'subscription_plan', 'subscription_amount', 'notes', 'max_sub_doctors'
        ]);

        if ($request->filled('password')) {
            $updateData['password'] = Hash::make($request->password);
        }

        $doctor->update($updateData);

        return response()->json([
            'message' => 'Doctor updated successfully',
            'doctor' => $doctor
        ]);
    }

    /**
     * Delete a doctor.
     */
    public function destroyDoctor(User $doctor)
    {
        // Ensure it's a doctor
        if ($doctor->role !== 'doctor') {
            return response()->json(['error' => 'User is not a doctor'], 404);
        }

        // Check if doctor has assistants or clients
        if ($doctor->assistants()->count() > 0 || $doctor->clients()->count() > 0) {
            return response()->json([
                'error' => 'Cannot delete doctor with existing assistants or clients. Please reassign or delete them first.'
            ], 422);
        }

        $doctor->delete();

        return response()->json(['message' => 'Doctor deleted successfully']);
    }

    /**
     * Get subscription statistics.
     */
    public function subscriptionStats()
    {
        $stats = [
            'total_doctors' => User::where('role', 'doctor')->count(),
            'active_subscriptions' => User::where('role', 'doctor')
                ->where('is_active', true)
                ->where('subscription_end', '>', now())
                ->count(),
            'expired_subscriptions' => User::where('role', 'doctor')
                ->where(function ($query) {
                    $query->where('is_active', false)
                          ->orWhere('subscription_end', '<=', now());
                })
                ->count(),
            'no_subscription' => User::where('role', 'doctor')
                ->whereNull('subscription_end')
                ->count(),
            'total_revenue' => User::where('role', 'doctor')
                ->whereNotNull('subscription_amount')
                ->sum('subscription_amount'),
        ];

        return response()->json($stats);
    }
}
