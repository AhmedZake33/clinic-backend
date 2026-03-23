<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AssistantController extends Controller
{
    /**
     * List assistants for the authenticated doctor.
     */
    public function index(Request $request)
    {
        $doctor = $request->user();

        $assistants = User::where('doctor_id', $doctor->id)
            ->where('role', 'assistant')
            ->latest()
            ->get(['id', 'name', 'email', 'doctor_id', 'created_at']);

        return response()->json($assistants);
    }

    /**
     * Create a new assistant for the authenticated doctor.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users',
            'password' => 'required|min:8|confirmed',
        ]);

        $doctor = $request->user();

        $assistant = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => 'assistant',
            'doctor_id' => $doctor->id,
        ]);

        $assistant->assignRole('assistant');

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

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $assistant->id,
            'password' => 'nullable|min:8|confirmed',
        ]);

        $data = [
            'name' => $request->name,
            'email' => $request->email,
        ];

        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        $assistant->update($data);

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
}
