<?php

namespace App\Http\Controllers;

use App\Http\Traits\ResolvesDoctor;
use App\Models\Client;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    use ResolvesDoctor;

    public function index(Request $request)
    {
        $doctorId = $this->requireDoctorId($request);

        $query = Client::with('creator')
            ->where('doctor_id', $doctorId);

        // Optional search by name/email/phone
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        // Optional created date range filters
        if ($from = $request->query('created_from')) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->query('created_to')) {
            $query->whereDate('created_at', '<=', $to);
        }

        $clients = $query->latest()->paginate(10);
        return response()->json($clients);
    }

    public function store(Request $request)
    {
        $doctorId = $this->requireDoctorId($request);

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|unique:clients',
            'phone' => 'required|string|max:20',
            'date_of_birth' => 'nullable|date',
            'height' => 'nullable|numeric|min:0|max:300',
            'weight' => 'nullable|numeric|min:0|max:500',
            'address' => 'nullable|string',
            'medical_history' => 'nullable|string',
        ]);

        $client = Client::create([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'date_of_birth' => $request->date_of_birth,
            'height' => $request->height,
            'weight' => $request->weight,
            'address' => $request->address,
            'medical_history' => $request->medical_history,
            'created_by' => $request->user()->id,
            'doctor_id' => $doctorId,
        ]);

        return response()->json($client, 201);
    }

    public function show(Request $request, Client $client)
    {
        $doctorId = $this->requireDoctorId($request);

        if ($client->doctor_id !== $doctorId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $client->load(['creator', 'reservations.doctor']);
        return response()->json($client);
    }

    public function update(Request $request, Client $client)
    {
        $doctorId = $this->requireDoctorId($request);

        if ($client->doctor_id !== $doctorId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|unique:clients,email,' . $client->id,
            'phone' => 'required|string|max:20',
            'date_of_birth' => 'nullable|date',
            'height' => 'nullable|numeric|min:0|max:300',
            'weight' => 'nullable|numeric|min:0|max:500',
            'address' => 'nullable|string',
            'medical_history' => 'nullable|string',
        ]);

        $client->update($request->only([
            'name', 'email', 'phone', 'date_of_birth', 'height', 'weight', 'address', 'medical_history',
        ]));

        return response()->json($client);
    }

    public function destroy(Request $request, Client $client)
    {
        $doctorId = $this->requireDoctorId($request);

        if ($client->doctor_id !== $doctorId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $client->delete();
        return response()->json(['message' => 'Client deleted successfully']);
    }

    public function timeline(Request $request, Client $client)
    {
        $doctorId = $this->requireDoctorId($request);

        if ($client->doctor_id !== $doctorId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $query = $client->reservations()
            ->with(['doctor:id,name', 'financial:id,reservation_id,amount,paid,remaining,payment_status'])
            ->orderBy('appointment_date', 'desc');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($from = $request->query('date_from')) {
            $query->whereDate('appointment_date', '>=', $from);
        }
        if ($to = $request->query('date_to')) {
            $query->whereDate('appointment_date', '<=', $to);
        }

        $reservations = $query->get();

        $completed = $reservations->where('status', 'completed')->count();

        return response()->json([
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
            ],
            'summary' => [
                'total_visits' => $reservations->count(),
                'total_completed' => $completed,
                'last_visit' => $reservations->first()?->appointment_date,
            ],
            'timeline' => $reservations,
        ]);
    }
}
