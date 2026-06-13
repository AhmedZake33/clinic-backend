<?php

namespace App\Http\Controllers;

use App\Http\Traits\NormalizesPhoneNumbers;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AssistantController extends Controller
{
    use NormalizesPhoneNumbers;

    /**
     * List assistants for the authenticated doctor.
     */
    public function index(Request $request)
    {
        $doctor = $request->user();

        $assistants = User::where('doctor_id', $doctor->id)
            ->where('role', 'assistant')
            ->latest()
            ->get(['id', 'name', 'email', 'phone', 'whatsapp_number', 'doctor_id', 'created_at']);

        $assistants->each(function (User $assistant) {
            $assistant->setAttribute('permissions', $assistant->getDirectPermissions()->pluck('name')->values());
        });

        return response()->json($assistants);
    }

    public function permissions()
    {
        $permissions = collect($this->manageablePermissions())
            ->map(fn ($label, $name) => [
                'name' => $name,
                'label' => $label,
                'group' => explode('.', $name)[0] ?? 'assistant',
            ])
            ->values();

        return response()->json($permissions);
    }

    /**
     * Create a new assistant for the authenticated doctor.
     */
    public function store(Request $request)
    {
        $this->normalizePhoneInputs($request);
        $this->normalizePermissionInput($request);

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users',
            'phone' => 'nullable|string|max:20',
            'whatsapp_number' => 'nullable|string|max:20',
            'password' => 'required|min:8|confirmed',
            'permissions' => 'nullable|array',
            'permissions.*' => ['string', Rule::in(array_keys($this->manageablePermissions()))],
        ]);

        $doctor = $request->user();

        $assistant = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'whatsapp_number' => $request->whatsapp_number,
            'password' => Hash::make($request->password),
            'role' => 'assistant',
            'doctor_id' => $doctor->id,
        ]);

        $assistant->assignRole('assistant');
        $assistant->syncPermissions($request->input('permissions', $this->defaultPermissions()));
        $assistant->setAttribute('permissions', $assistant->getDirectPermissions()->pluck('name')->values());

        return response()->json($assistant, 201);
    }

    /**
     * Update an assistant.
     */
    public function update(Request $request, User $assistant)
    {
        $doctor = $request->user();

        // Ensure the assistant belongs to this doctor
        if ($assistant->doctor_id !== $doctor->id || $assistant->role !== 'assistant') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $this->normalizePhoneInputs($request);
        $this->normalizePermissionInput($request);

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $assistant->id,
            'phone' => 'nullable|string|max:20',
            'whatsapp_number' => 'nullable|string|max:20',
            'password' => 'nullable|min:8|confirmed',
            'permissions' => 'nullable|array',
            'permissions.*' => ['string', Rule::in(array_keys($this->manageablePermissions()))],
        ]);

        $data = [
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'whatsapp_number' => $request->whatsapp_number,
        ];

        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        $assistant->update($data);
        $assistant->syncPermissions($request->input('permissions', $assistant->getDirectPermissions()->pluck('name')->all()));
        $assistant->setAttribute('permissions', $assistant->getDirectPermissions()->pluck('name')->values());

        return response()->json($assistant);
    }

    /**
     * Remove an assistant.
     */
    public function destroy(Request $request, User $assistant)
    {
        $doctor = $request->user();

        // Ensure the assistant belongs to this doctor
        if ($assistant->doctor_id !== $doctor->id || $assistant->role !== 'assistant') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $assistant->delete();

        return response()->json(['message' => 'Assistant removed successfully']);
    }

    private function manageablePermissions(): array
    {
        return [
            'assistant.view-dashboard' => 'View dashboard',
            'assistant.view-clients' => 'View clients',
            'assistant.create-clients' => 'Create clients',
            'assistant.edit-clients' => 'Edit clients',
            'assistant.delete-clients' => 'Delete clients',
            'assistant.view-reservations' => 'View reservations',
            'assistant.create-reservations' => 'Create reservations',
            'assistant.edit-reservations' => 'Edit reservations',
            'assistant.delete-reservations' => 'Delete reservations',
            'assistant.confirm-reservations' => 'Confirm reservations',
            'assistant.complete-reservations' => 'Complete reservations',
            'assistant.view-financials' => 'View financials',
            'assistant.create-financials' => 'Create financials',
            'assistant.edit-financials' => 'Edit financials',
            'assistant.delete-financials' => 'Delete financials',
            'assistant.view-purchases' => 'View purchases',
            'assistant.create-purchases' => 'Create purchases',
            'assistant.edit-purchases' => 'Edit purchases',
            'assistant.delete-purchases' => 'Delete purchases',
            'assistant.view-reports' => 'View reports',
            'assistant.export-reports' => 'Export reports',
            'assistant.view-waiting-queue' => 'View waiting queue',
            'assistant.check-in-patients' => 'Check in patients',
            'assistant.view-assistant-calls' => 'View assistant calls',
            'assistant.accept-assistant-calls' => 'Accept assistant calls',
        ];
    }

    private function defaultPermissions(): array
    {
        return array_keys($this->manageablePermissions());
    }

    private function normalizePermissionInput(Request $request): void
    {
        if (!$request->has('permissions') || !is_array($request->input('permissions'))) {
            return;
        }

        $permissions = collect($request->input('permissions'))
            ->map(function ($permission) {
                if (is_string($permission)) {
                    return $permission;
                }

                if (is_array($permission)) {
                    return $permission['name'] ?? $permission['value'] ?? null;
                }

                return null;
            })
            ->filter()
            ->filter(fn ($permission) => array_key_exists($permission, $this->manageablePermissions()))
            ->values()
            ->all();

        $request->merge(['permissions' => $permissions]);
    }
}
