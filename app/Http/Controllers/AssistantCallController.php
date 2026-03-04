<?php

namespace App\Http\Controllers;

use App\Events\AssistantCallEvent;
use App\Models\AssistantCall;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssistantCallController extends Controller
{
    /**
     * Doctor creates a new assistant call.
     * clinic_id = the doctor's own id (tenant scope).
     */
    public function createCall(Request $request): JsonResponse
    {
        $doctor = $request->user();

        // Only doctors may create calls
        if ($doctor->role !== 'doctor') {
            return response()->json(['message' => 'Only doctors can create assistant calls.'], 403);
        }

        $request->validate([
            'assistant_id' => 'required|exists:users,id',
            'message'      => 'nullable|string|max:500',
        ]);

        // Ensure the chosen assistant belongs to this doctor (clinic)
        $assistant = User::where('id', $request->assistant_id)
            ->where('doctor_id', $doctor->id)
            ->where('role', 'assistant')
            ->first();

        if (!$assistant) {
            return response()->json(['message' => 'This assistant does not belong to your clinic.'], 422);
        }

        // clinic_id is the doctor's own id (doctors are tenant owners)
        $call = AssistantCall::create([
            'doctor_id'    => $doctor->id,
            'assistant_id' => $assistant->id,
            'clinic_id'    => $doctor->id, // doctor IS the clinic owner
            'status'       => 'pending',
            'message'      => $request->input('message'),
        ]);

        $call->load(['doctor', 'assistant']);

        event(new AssistantCallEvent($call, 'created'));

        return response()->json([
            'message' => 'Assistant call created successfully.',
            'call'    => $call,
        ], 201);
    }

    /**
     * Get active (pending / accepted) calls.
     * Doctors see their own calls; assistants see calls for their clinic.
     */
    public function getActiveCalls(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = AssistantCall::with(['doctor', 'assistant'])->active();

        if ($user->role === 'doctor') {
            $query->where('doctor_id', $user->id);
        } elseif ($user->role === 'assistant') {
            // Only show calls targeted at this specific assistant
            $query->where('assistant_id', $user->id);
        } else {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $calls = $query->orderByDesc('created_at')->get();

        return response()->json($calls);
    }

    /**
     * Assistant accepts a pending call.
     */
    public function acceptCall(Request $request, AssistantCall $call): JsonResponse
    {
        $assistant = $request->user();

        if ($assistant->role !== 'assistant') {
            return response()->json(['message' => 'Only assistants can accept calls.'], 403);
        }

        // Ensure this call is assigned to this assistant
        if ((int) $call->assistant_id !== (int) $assistant->id) {
            return response()->json(['message' => 'This call is not assigned to you.'], 403);
        }

        if ($call->status !== 'pending') {
            return response()->json(['message' => 'This call has already been handled.'], 422);
        }

        $call->update([
            'status' => 'accepted',
        ]);

        $call->load(['doctor', 'assistant']);

        event(new AssistantCallEvent($call, 'accepted'));

        return response()->json([
            'message' => 'Call accepted.',
            'call'    => $call,
        ]);
    }

    /**
     * Mark call as done (by assistant or doctor).
     */
    public function completeCall(Request $request, AssistantCall $call): JsonResponse
    {
        $user = $request->user();

        // Doctor can complete their own call; assistant can complete calls they accepted
        $allowed = false;
        if ($user->role === 'doctor' && (int) $call->doctor_id === (int) $user->id) {
            $allowed = true;
        }
        if ($user->role === 'assistant' && (int) $call->assistant_id === (int) $user->id) {
            $allowed = true;
        }

        if (!$allowed) {
            return response()->json(['message' => 'You are not authorized to complete this call.'], 403);
        }

        if ($call->status === 'done') {
            return response()->json(['message' => 'This call is already completed.'], 422);
        }

        $call->update(['status' => 'done']);
        $call->load(['doctor', 'assistant']);

        event(new AssistantCallEvent($call, 'completed'));

        return response()->json([
            'message' => 'Call completed.',
            'call'    => $call,
        ]);
    }
}
