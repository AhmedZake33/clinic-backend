<?php

namespace App\Http\Controllers;

use App\Http\Traits\NormalizesPhoneNumbers;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;

class SubDoctorController extends Controller
{
    use NormalizesPhoneNumbers;

    // List sub-doctors for authenticated doctor
    public function index(Request $request)
    {
        $doctor = $request->user();
        $subs = $doctor->subDoctors()->get();
        return response()->json([
            'sub_doctors' => $subs,
            'max_sub_doctors' => (int) ($doctor->max_sub_doctors ?? 0),
            'current_count' => $subs->count(),
        ]);
    }

    // Create sub-doctor
    public function store(Request $request)
    {
        $doctor = $request->user();
        $this->normalizePhoneInputs($request);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'phone' => 'nullable|string|max:20',
            'whatsapp_number' => 'nullable|string|max:20',
            'password' => 'nullable|string|min:6',
        ]);

        // Enforce max_sub_doctors limit
        $maxAllowed = (int) ($doctor->max_sub_doctors ?? 0);
        $currentCount = $doctor->subDoctors()->count();

        if ($maxAllowed <= 0) {
            return response()->json(['message' => 'Your plan does not allow adding sub-doctors. Please contact the administrator.'], 403);
        }
        if ($currentCount >= $maxAllowed) {
            return response()->json(['message' => "You have reached the maximum number of sub-doctors allowed ({$maxAllowed})."], 422);
        }

        $plainPassword = $data['password'] ?? \Str::random(10);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'whatsapp_number' => $data['whatsapp_number'] ?? null,
            'password' => Hash::make($plainPassword),
            'role' => 'sub-doctor',
            'parent_doctor_id' => $doctor->id,
        ]);

        // Assign the dedicated sub-doctor role
        $user->syncRoles(['sub-doctor']);

        // Do NOT allow parent doctor to assign permissions. Admin will manage permissions.

        return response()->json(array_merge($user->toArray(), ['plain_password' => $plainPassword]), 201);
    }

    // Update sub-doctor
    public function update(Request $request, User $user)
    {
        $doctor = $request->user();
        if ($user->parent_doctor_id !== $doctor->id) {
            return response()->json(['message' => 'Not authorized'], 403);
        }

        $this->normalizePhoneInputs($request);

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|email|unique:users,email,'.$user->id,
            'phone' => 'nullable|string|max:20',
            'whatsapp_number' => 'nullable|string|max:20',
            'password' => 'nullable|string|min:6',
            'is_active' => 'boolean',
        ]);

        if (isset($data['name'])) $user->name = $data['name'];
        if (isset($data['email'])) $user->email = $data['email'];
        if (array_key_exists('phone', $data)) $user->phone = $data['phone'];
        if (array_key_exists('whatsapp_number', $data)) $user->whatsapp_number = $data['whatsapp_number'];
        if (!empty($data['password'])) $user->password = Hash::make($data['password']);
        if (isset($data['is_active'])) $user->is_active = $data['is_active'];
        $user->save();

        // Permissions for sub-doctors must be assigned by admin; parent doctor cannot change them.

        return response()->json($user);
    }

    // Delete sub-doctor
    public function destroy(Request $request, User $user)
    {
        $doctor = $request->user();
        if ($user->parent_doctor_id !== $doctor->id) {
            return response()->json(['message' => 'Not authorized'], 403);
        }

        $user->delete();
        return response()->json(['message' => 'Deleted']);
    }

    // List available doctor permissions
    public function permissions()
    {
        $perms = Permission::where('name', 'like', 'doctor.%')->get()->map(function ($p) {
            return ['id' => $p->id, 'name' => $p->name];
        });
        return response()->json($perms);
    }
}
