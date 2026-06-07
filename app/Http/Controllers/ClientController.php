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
        // Clients are stored under the primary (parent) doctor.
        // Sub-doctors share the same client pool as their parent doctor.
        $user = $request->user();
        $primaryDoctorId = ($user->role === 'sub-doctor' && $user->parent_doctor_id)
            ? $user->parent_doctor_id
            : $this->requireDoctorId($request);

        $query = Client::with('creator')
            ->where('doctor_id', $primaryDoctorId);

        // Optional search by name/email/phone
        if ($search = $request->query('search')) {
            $phoneSearches = $this->phoneSearchTerms($search);

            $query->where(function ($q) use ($search, $phoneSearches) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('whatsapp_number', 'like', "%{$search}%");

                foreach ($phoneSearches as $term) {
                    $q->orWhere('phone', 'like', "%{$term}%")
                        ->orWhere('whatsapp_number', 'like', "%{$term}%");
                }
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
        // Clients are always stored under the primary (parent) doctor so they are
        // shared between the doctor and all sub-doctors.
        $user = $request->user();
        $doctorId = ($user->role === 'sub-doctor' && $user->parent_doctor_id)
            ? $user->parent_doctor_id
            : $this->requireDoctorId($request);

        $this->normalizeClientPhoneInputs($request);

        $request->validate($this->clientRules($doctorId));

        $client = Client::create([
            ...$this->clientPayload($request),
            'created_by' => $request->user()->id,
            'doctor_id' => $doctorId,
        ]);

        return response()->json($client, 201);
    }

    public function show(Request $request, Client $client)
    {
        $user = $request->user();
        $primaryDoctorId = ($user->role === 'sub-doctor' && $user->parent_doctor_id)
            ? $user->parent_doctor_id
            : $this->requireDoctorId($request);

        if ($client->doctor_id !== $primaryDoctorId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $client->load(['creator', 'reservations.doctor']);
        return response()->json($client);
    }

    public function update(Request $request, Client $client)
    {
        $user = $request->user();
        $primaryDoctorId = ($user->role === 'sub-doctor' && $user->parent_doctor_id)
            ? $user->parent_doctor_id
            : $this->requireDoctorId($request);

        if ($client->doctor_id !== $primaryDoctorId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $this->normalizeClientPhoneInputs($request);

        $request->validate($this->clientRules($primaryDoctorId, $client));

        $client->update($this->clientPayload($request));

        return response()->json($client);
    }

    private function clientRules(int $doctorId, ?Client $client = null): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => ['nullable', 'email', Rule::unique('clients', 'email')->ignore($client?->id)],
            'phone' => [
                'required',
                'string',
                'max:20',
                Rule::unique('clients', 'phone')
                    ->where(fn ($query) => $query->where('doctor_id', $doctorId))
                    ->ignore($client?->id),
            ],
            'whatsapp_number' => 'nullable|string|max:20',
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

    private function normalizeClientPhoneInputs(Request $request): void
    {
        $request->merge([
            'phone' => $this->normalizePhoneNumber(
                $request->input('phone'),
                $request->input('phone_country_code') ?: $request->input('country_code')
            ),
            'whatsapp_number' => $this->normalizePhoneNumber(
                $request->input('whatsapp_number'),
                $request->input('whatsapp_country_code') ?: $request->input('country_code')
            ),
        ]);
    }

    private function normalizePhoneNumber($number, $countryCode = null): ?string
    {
        if (!filled($number)) {
            return null;
        }

        $rawNumber = trim((string) $number);
        $digits = preg_replace('/\D+/', '', $rawNumber);

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($rawNumber, '+')) {
            return '+' . ltrim($digits, '0');
        }

        $prefixDigits = preg_replace('/\D+/', '', (string) $countryCode);
        if ($prefixDigits) {
            return '+' . ltrim($prefixDigits, '0') . ltrim($digits, '0');
        }

        return $digits;
    }

    private function phoneSearchTerms(string $search): array
    {
        $digits = preg_replace('/\D+/', '', $search) ?? '';

        if ($digits === '') {
            return [];
        }

        $terms = [$digits];

        if (str_starts_with($digits, '0020')) {
            $terms[] = substr($digits, 2);
        }

        if (str_starts_with($digits, '20')) {
            $terms[] = '0' . substr($digits, 2);
            $terms[] = substr($digits, 2);
        } elseif (str_starts_with($digits, '0')) {
            $terms[] = '20' . substr($digits, 1);
        } else {
            $terms[] = '20' . ltrim($digits, '0');
            $terms[] = '0' . ltrim($digits, '0');
        }

        return array_values(array_unique(array_filter($terms)));
    }

    private function clientPayload(Request $request): array
    {
        return [
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'whatsapp_number' => $request->whatsapp_number,
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
        $user = $request->user();
        $primaryDoctorId = ($user->role === 'sub-doctor' && $user->parent_doctor_id)
            ? $user->parent_doctor_id
            : $this->requireDoctorId($request);

        if ($client->doctor_id !== $primaryDoctorId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $client->delete();
        return response()->json(['message' => 'Client deleted successfully']);
    }
}
