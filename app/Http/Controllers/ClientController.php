<?php

namespace App\Http\Controllers;

use App\Http\Traits\ResolvesDoctor;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClientController extends Controller
{
    use ResolvesDoctor;

    public function options()
    {
        return response()->json([
            'chronic_illnesses' => Client::chronicIllnessOptions(),
        ]);
    }

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
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('whatsapp_number', 'like', "%{$search}%");
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

        $request->validate($this->clientRules());

        $client = Client::create([
            ...$this->clientPayload($request),
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

        $request->validate($this->clientRules($client));

        $client->update($this->clientPayload($request));

        return response()->json($client);
    }

    private function clientRules(?Client $client = null): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => ['nullable', 'email', Rule::unique('clients', 'email')->ignore($client?->id)],
            'phone' => 'required|string|max:20',
            'phone_country_code' => 'nullable|string|max:10',
            'whatsapp_number' => 'nullable|string|max:20',
            'whatsapp_country_code' => 'nullable|string|max:10',
            'date_of_birth' => 'nullable|date',
            'height' => 'nullable|numeric|min:0|max:300',
            'weight' => 'nullable|numeric|min:0|max:500',
            'address' => 'nullable|string',
            'job' => 'nullable|string|max:255',
            'medical_history' => 'nullable|string',
            'chronic_illnesses' => 'nullable|array',
            'chronic_illnesses.*' => ['string', Rule::in(Client::chronicIllnessOptions())],
            'blood_type' => ['nullable', Rule::in(Client::bloodTypeOptions())],
        ];
    }

    private function clientPayload(Request $request): array
    {
        return [
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'phone_country_code' => $request->phone_country_code,
            'whatsapp_number' => $request->whatsapp_number,
            'whatsapp_country_code' => $request->whatsapp_country_code,
            'date_of_birth' => $request->date_of_birth,
            'height' => $request->height,
            'weight' => $request->weight,
            'address' => $request->address,
            'job' => $request->job,
            'blood_type' => $request->blood_type,
            'medical_history' => $request->medical_history,
            'chronic_illnesses' => $this->sanitizeChronicIllnesses($request->input('chronic_illnesses')),
        ];
    }

    private function sanitizeChronicIllnesses($values): array
    {
        if (!is_array($values)) {
            $values = [];
        }

        return array_values(array_unique(array_filter($values, static fn ($value) => filled($value))));
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
}
