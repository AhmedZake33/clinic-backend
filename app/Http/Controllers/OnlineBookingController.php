<?php

namespace App\Http\Controllers;

use App\Http\Traits\ResolvesDoctor;
use App\Models\Client;
use App\Models\DoctorAvailability;
use App\Models\DoctorHoliday;
use App\Models\Reservation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class OnlineBookingController extends Controller
{
    use ResolvesDoctor;

    private function primaryDoctorBySlug(string $slug): ?User
    {
        return User::where('role', 'doctor')
            ->where('booking_slug', $slug)
            ->first();
    }

    private function scopedDoctor(User $primaryDoctor, int $doctorId): ?User
    {
        return User::where('id', $doctorId)
            ->whereIn('role', ['doctor', 'sub-doctor'])
            ->where(function ($query) use ($primaryDoctor) {
                $query->where('id', $primaryDoctor->id)
                    ->orWhere('parent_doctor_id', $primaryDoctor->id);
            })
            ->first();
    }

    private function ensurePublicBookingAvailable(?User $doctor): ?\Illuminate\Http\JsonResponse
    {
        if (!$doctor || $doctor->isSubscriptionExpired()) {
            return response()->json(['message' => 'Booking page is not available.'], 404);
        }

        return null;
    }

    public function show(string $slug)
    {
        $doctor = $this->primaryDoctorBySlug($slug);
        if ($response = $this->ensurePublicBookingAvailable($doctor)) {
            return $response;
        }

        $doctors = collect([$doctor])
            ->merge($doctor->subDoctors()->where('is_active', true)->get())
            ->map(fn (User $item) => [
                'id' => $item->id,
                'name' => $item->name,
                'specialization' => $item->specialization,
            ])
            ->values();

        return response()->json([
            'clinic' => [
                'name' => $doctor->print_clinic_name ?: $doctor->name,
                'header' => $doctor->print_header_text,
                'primary_color' => $doctor->print_primary_color ?: '#7367f0',
                'position' => $doctor->print_clinic_name_position ?: 'center',
                'phone' => $doctor->print_clinic_phone ?: $doctor->phone,
                'address' => $doctor->print_clinic_address,
            ],
            'doctors' => $doctors,
        ]);
    }

    public function availableTimes(Request $request, string $slug, User $doctor)
    {
        $primaryDoctor = $this->primaryDoctorBySlug($slug);
        if (($response = $this->ensurePublicBookingAvailable($primaryDoctor)) || !$this->scopedDoctor($primaryDoctor, $doctor->id)) {
            return $response ?: response()->json(['message' => 'Selected doctor is not available.'], 404);
        }

        $request->validate(['date' => 'required|date|after_or_equal:today']);

        $date = Carbon::parse($request->date);
        $dayOfWeek = (int) $date->dayOfWeek;

        if ($this->isHoliday($doctor, $date, $dayOfWeek)) {
            return response()->json(['available' => false, 'slots' => []]);
        }

        return response()->json([
            'available' => true,
            'slots' => $this->availableSlots($doctor, $date, $dayOfWeek),
        ]);
    }

    public function store(Request $request, string $slug)
    {
        $primaryDoctor = $this->primaryDoctorBySlug($slug);
        if ($response = $this->ensurePublicBookingAvailable($primaryDoctor)) {
            return $response;
        }

        $validated = $request->validate([
            'doctor_id' => 'required|integer',
            'appointment_date' => 'required|date|after_or_equal:today',
            'appointment_time' => 'required|date_format:H:i',
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:30',
            'whatsapp_number' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:255',
            'notes' => 'nullable|string|max:2000',
        ]);

        $rateKey = 'online-booking:' . $slug . ':' . $request->ip() . ':' . preg_replace('/\D+/', '', $validated['phone']);
        if (RateLimiter::tooManyAttempts($rateKey, 1)) {
            return response()->json([
                'message' => 'You already submitted an online booking request. Please contact the clinic.',
            ], 429);
        }

        $doctor = $this->scopedDoctor($primaryDoctor, (int) $validated['doctor_id']);
        if (!$doctor) {
            return response()->json(['message' => 'Selected doctor is not available.'], 422);
        }

        $appointment = Carbon::parse($validated['appointment_date'] . ' ' . $validated['appointment_time']);
        $dayOfWeek = (int) $appointment->dayOfWeek;
        $time = $appointment->format('H:i');

        if ($this->isHoliday($doctor, $appointment, $dayOfWeek) || !$this->hasAvailability($doctor, $dayOfWeek, $time) || $this->hasConflict($doctor, $appointment)) {
            return response()->json(['message' => 'Selected appointment is no longer available.'], 422);
        }

        $client = $this->findExistingClientByPhone($primaryDoctor, $validated['phone']);
        $existingClient = (bool) $client;

        if (!$client) {
            $client = Client::create([
                'doctor_id' => $primaryDoctor->id,
                'phone' => $validated['phone'],
                'name' => $validated['name'],
                'email' => $validated['email'] ?? null,
                'whatsapp_number' => $validated['whatsapp_number'] ?? null,
                'created_by' => $primaryDoctor->id,
            ]);
        }

        $reservation = Reservation::create([
            'client_id' => $client->id,
            'doctor_id' => $doctor->id,
            'created_by' => $primaryDoctor->id,
            'appointment_date' => $appointment,
            'status' => 'pending',
            'source' => 'online',
            'online_booking_existing_client' => $existingClient,
            'notes' => $validated['notes'] ?? null,
            'online_booking_ip' => $request->ip(),
            'online_booking_user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
        ]);

        RateLimiter::hit($rateKey, 86400);

        return response()->json([
            'message' => 'Booking request submitted successfully.',
            'reservation_id' => $reservation->id,
        ], 201);
    }

    public function settings(Request $request)
    {
        $user = $request->user();
        abort_unless($user->role === 'doctor', 403);

        return response()->json([
            'booking_slug' => $user->booking_slug,
            'booking_url' => $this->bookingUrl($request, $user->booking_slug),
            'clinic_name' => $user->print_clinic_name,
            'clinic_header' => $user->print_header_text,
            'clinic_phone' => $user->print_clinic_phone,
            'clinic_primary_color' => $user->print_primary_color ?: '#7367f0',
            'clinic_position' => $user->print_clinic_name_position ?: 'center',
        ]);
    }

    public function updateSettings(Request $request)
    {
        $user = $request->user();
        abort_unless($user->role === 'doctor', 403);

        $validated = $request->validate([
            'booking_slug' => [
                'required',
                'string',
                'min:3',
                'max:80',
                'regex:/^[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*$/',
                Rule::unique('users', 'booking_slug')->ignore($user->id),
            ],
            'clinic_name' => 'nullable|string|max:255',
            'clinic_header' => 'nullable|string|max:2000',
            'clinic_phone' => 'nullable|string|max:255',
            'clinic_primary_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'clinic_position' => 'nullable|in:left,center,right',
        ]);

        $user->update([
            'booking_slug' => $validated['booking_slug'],
            'print_clinic_name' => $validated['clinic_name'] ?? null,
            'print_header_text' => $validated['clinic_header'] ?? null,
            'print_clinic_phone' => $validated['clinic_phone'] ?? null,
            'print_primary_color' => $validated['clinic_primary_color'] ?? '#7367f0',
            'print_clinic_name_position' => $validated['clinic_position'] ?? 'center',
        ]);

        return response()->json([
            'booking_slug' => $user->booking_slug,
            'booking_url' => $this->bookingUrl($request, $user->booking_slug),
            'clinic_name' => $user->print_clinic_name,
            'clinic_header' => $user->print_header_text,
            'clinic_phone' => $user->print_clinic_phone,
            'clinic_primary_color' => $user->print_primary_color ?: '#7367f0',
            'clinic_position' => $user->print_clinic_name_position ?: 'center',
        ]);
    }

    public function onlineReservations(Request $request)
    {
        $doctorIds = $this->getDoctorIds($request);

        $reservations = Reservation::with(['client', 'doctor'])
            ->whereIn('doctor_id', $doctorIds)
            ->where('source', 'online')
            ->where('status', 'pending')
            ->latest()
            ->paginate(15);

        $reservations->getCollection()->transform(function (Reservation $reservation) use ($doctorIds) {
            $reservation->online_booking_existing_client = $reservation->online_booking_existing_client
                || $this->reservationMatchesExistingClient($reservation, $doctorIds);

            return $reservation;
        });

        return $reservations;
    }

    private function isHoliday(User $doctor, Carbon $date, int $dayOfWeek): bool
    {
        return DoctorHoliday::where('user_id', $doctor->id)
            ->where(function ($query) use ($date, $dayOfWeek) {
                $query->whereDate('date', $date->toDateString())
                    ->orWhere('recurring_day_of_week', $dayOfWeek);
            })
            ->exists();
    }

    private function hasAvailability(User $doctor, int $dayOfWeek, string $time): bool
    {
        return DoctorAvailability::where('user_id', $doctor->id)
            ->where('day_of_week', $dayOfWeek)
            ->where('start_time', '<=', $time)
            ->where('end_time', '>=', $time)
            ->exists();
    }

    private function hasConflict(User $doctor, Carbon $appointment): bool
    {
        return Reservation::where('doctor_id', $doctor->id)
            ->where('appointment_date', $appointment)
            ->where('status', '!=', 'cancelled')
            ->exists();
    }

    private function findExistingClientByPhone(User $primaryDoctor, string $phone): ?Client
    {
        return Client::where('doctor_id', $primaryDoctor->id)
            ->get()
            ->first(fn (Client $client) => $this->phonesMatch($client->phone, $phone));
    }

    private function reservationMatchesExistingClient(Reservation $reservation, array $doctorIds): bool
    {
        if (!$reservation->client) {
            return false;
        }

        if ($reservation->client->created_at && $reservation->created_at && $reservation->client->created_at->lt($reservation->created_at)) {
            return true;
        }

        return Client::whereIn('doctor_id', $doctorIds)
            ->where('id', '!=', $reservation->client_id)
            ->where('created_at', '<=', $reservation->created_at)
            ->get()
            ->contains(fn (Client $client) => $this->phonesMatch($client->phone, $reservation->client->phone));
    }

    private function phonesMatch(?string $left, ?string $right): bool
    {
        $left = $this->normalizePhoneDigits($left);
        $right = $this->normalizePhoneDigits($right);

        if (!$left || !$right) {
            return false;
        }

        if ($left === $right) {
            return true;
        }

        $minLength = min(strlen($left), strlen($right));
        if ($minLength < 8) {
            return false;
        }

        return Str::endsWith($left, $right) || Str::endsWith($right, $left);
    }

    private function normalizePhoneDigits(?string $phone): string
    {
        return ltrim(preg_replace('/\D+/', '', (string) $phone), '0');
    }

    private function availableSlots(User $doctor, Carbon $date, int $dayOfWeek): array
    {
        $bookedTimes = Reservation::where('doctor_id', $doctor->id)
            ->whereDate('appointment_date', $date->toDateString())
            ->where('status', '!=', 'cancelled')
            ->pluck('appointment_date')
            ->map(fn ($dt) => Carbon::parse($dt)->format('H:i'))
            ->toArray();

        $slots = [];
        $availabilities = DoctorAvailability::where('user_id', $doctor->id)
            ->where('day_of_week', $dayOfWeek)
            ->orderBy('start_time')
            ->get();

        foreach ($availabilities as $availability) {
            $start = Carbon::parse($availability->start_time);
            $end = Carbon::parse($availability->end_time);
            $duration = max(5, (int) ($availability->slot_duration_minutes ?? 30));

            while ($start->lt($end)) {
                $time = $start->format('H:i');
                if (!in_array($time, $bookedTimes, true)) {
                    $slots[] = ['time' => $time, 'label' => $start->format('h:i A')];
                }
                $start->addMinutes($duration);
            }
        }

        return $slots;
    }

    private function bookingUrl(Request $request, ?string $slug): ?string
    {
        if (!$slug) {
            return null;
        }

        $frontend = rtrim((string) env('FRONTEND_URL', $request->getSchemeAndHttpHost()), '/');
        return $frontend . '/' . $slug;
    }
}
