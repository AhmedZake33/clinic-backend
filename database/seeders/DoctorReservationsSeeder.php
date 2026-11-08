<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\DoctorAvailability;
use App\Models\DoctorHoliday;
use App\Models\Financial;
use App\Models\Reservation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Sample reservations for doctor@clinic.com across different days and slots.
 *
 *   php artisan db:seed --class=DoctorReservationsSeeder
 *
 * Safe to re-run: existing clients are reused and occupied slots are skipped.
 */
class DoctorReservationsSeeder extends Seeder
{
    private const DOCTOR_EMAIL = 'doctor@clinic.com';
    private const DAYS_BACK = 3;      // completed history
    private const DAYS_AHEAD = 7;     // upcoming
    private const PER_DAY = 4;        // reservations per working day

    private array $patients = [
        ['Ahmed Hassan',     '01001234567', '1985-03-12'],
        ['Mona Ibrahim',     '01112345678', '1992-07-25'],
        ['Mahmoud Ali',      '01223456789', '1978-11-02'],
        ['Sara Mostafa',     '01534567890', '2000-01-18'],
        ['Omar Khaled',      '01045678901', '1995-05-30'],
        ['Nour El-Sayed',    '01156789012', '1988-09-09'],
        ['Youssef Adel',     '01267890123', '2010-12-21'],
        ['Fatma Abdelrahman','01578901234', '1965-04-14'],
        ['Karim Samir',      '01089012345', '1990-08-03'],
        ['Hana Tarek',       '01190123456', '1998-02-27'],
    ];

    public function run(): void
    {
        $doctor = User::where('email', self::DOCTOR_EMAIL)->first();
        if (!$doctor) {
            $this->command->error('Doctor ' . self::DOCTOR_EMAIL . ' not found. Run AdminUserSeeder first.');
            return;
        }

        $this->ensureAvailability($doctor);
        $clients = $this->ensureClients($doctor);

        $created = 0;
        $clientIdx = 0;
        $today = Carbon::today();

        DB::transaction(function () use ($doctor, $clients, $today, &$created, &$clientIdx) {
            for ($offset = -self::DAYS_BACK; $offset <= self::DAYS_AHEAD; $offset++) {
                $date = $today->copy()->addDays($offset);
                if ($this->isHoliday($doctor->id, $date)) {
                    continue;
                }

                $slots = $this->pickSlots($doctor->id, $date);
                $position = Reservation::where('doctor_id', $doctor->id)
                    ->whereDate('appointment_date', $date->toDateString())
                    ->max('position') ?? 0;
                $waiting = 0;

                foreach ($slots as $i => $slot) {
                    if (Reservation::where('doctor_id', $doctor->id)->where('appointment_date', $slot)->exists()) {
                        continue;
                    }

                    $status = $this->statusFor($offset, $i, count($slots));
                    $checkedIn = in_array($status, ['completed'], true)
                        || ($offset === 0 && $status === 'confirmed' && $i < 2);

                    $client = $clients[$clientIdx++ % count($clients)];

                    $reservation = Reservation::create([
                        'client_id'        => $client->id,
                        'doctor_id'        => $doctor->id,
                        'created_by'       => $doctor->id,
                        'appointment_date' => $slot->format('Y-m-d H:i:s'),
                        'status'           => $status,
                        'source'           => 'internal',
                        'notes'            => 'Sample reservation (seeded)',
                        'position'         => ++$position,
                        'checked_in_at'    => $checkedIn ? $slot->copy()->subMinutes(10) : null,
                        'waiting_number'   => $checkedIn ? ++$waiting : null,
                        'completed_at'     => $status === 'completed' ? $slot->copy()->addMinutes(20) : null,
                        'diagnosis'        => $status === 'completed' ? 'Routine check-up' : null,
                    ]);

                    $amount = [200, 300, 350, 500][$i % 4];
                    $paid = match ($status) {
                        'completed' => $amount,
                        'confirmed' => $amount / 2,
                        default     => 0,
                    };

                    Financial::create([
                        'reservation_id' => $reservation->id,
                        'client_id'      => $client->id,
                        'doctor_id'      => $doctor->id,
                        'created_by'     => $doctor->id,
                        'amount'         => $amount,
                        'paid'           => $paid,
                        'remaining'      => $amount - $paid,
                        'payment_status' => $paid <= 0 ? 'unpaid' : ($paid >= $amount ? 'paid' : 'partial'),
                        'payment_method' => 'cash',
                    ]);

                    $created++;
                }
            }
        });

        $this->command->info("Created {$created} reservations for {$doctor->name} (" . self::DOCTOR_EMAIL . ').');
    }

    /** If the doctor has no schedule, give them Sat–Thu 10:00–16:00 in 30-min slots. */
    private function ensureAvailability(User $doctor): void
    {
        if (DoctorAvailability::where('user_id', $doctor->id)->exists()) {
            return;
        }

        foreach ([6, 0, 1, 2, 3, 4] as $day) {
            DoctorAvailability::create([
                'user_id'               => $doctor->id,
                'day_of_week'           => $day,
                'start_time'            => '10:00',
                'end_time'              => '16:00',
                'slot_duration_minutes' => 30,
            ]);
        }
        $this->command->warn('Doctor had no availability — added Sat–Thu 10:00–16:00 (30 min slots).');
    }

    private function ensureClients(User $doctor): array
    {
        $clients = [];
        foreach ($this->patients as $n => [$name, $phone, $dob]) {
            $clients[] = Client::firstOrCreate(
                ['email' => 'seed.patient' . ($n + 1) . '.d' . $doctor->id . '@clinic.test'],
                [
                    'name'          => $name,
                    'phone'         => $phone,
                    'date_of_birth' => $dob,
                    'created_by'    => $doctor->id,
                    'doctor_id'     => $doctor->id,
                ]
            );
        }
        return $clients;
    }

    private function isHoliday(int $doctorId, Carbon $date): bool
    {
        return DoctorHoliday::where('user_id', $doctorId)
            ->where(function ($q) use ($date) {
                $q->whereDate('date', $date->toDateString())
                    ->orWhere('recurring_day_of_week', $date->dayOfWeek);
            })
            ->exists();
    }

    /** Spread PER_DAY reservations across the day's available slots. */
    private function pickSlots(int $doctorId, Carbon $date): array
    {
        $all = [];
        $windows = DoctorAvailability::where('user_id', $doctorId)
            ->where('day_of_week', $date->dayOfWeek)
            ->get();

        foreach ($windows as $w) {
            $step = max(5, (int) ($w->slot_duration_minutes ?: 30));
            $start = Carbon::parse($date->toDateString() . ' ' . Carbon::parse($w->getRawOriginal('start_time'))->format('H:i'));
            $end = Carbon::parse($date->toDateString() . ' ' . Carbon::parse($w->getRawOriginal('end_time'))->format('H:i'));
            for ($t = $start->copy(); $t->lt($end); $t->addMinutes($step)) {
                $all[] = $t->copy();
            }
        }

        usort($all, fn ($a, $b) => $a <=> $b);
        $count = count($all);
        if ($count <= self::PER_DAY) {
            return $all;
        }

        // Evenly spaced: e.g. morning, late morning, early afternoon, afternoon
        $picked = [];
        for ($i = 0; $i < self::PER_DAY; $i++) {
            $picked[] = $all[(int) floor($i * $count / self::PER_DAY) + ($i % 2)] ?? $all[$count - 1];
        }
        return $picked;
    }

    private function statusFor(int $dayOffset, int $i, int $total): string
    {
        if ($dayOffset < 0) {
            return $i === $total - 1 ? 'cancelled' : 'completed';
        }
        if ($dayOffset === 0) {
            return ['completed', 'confirmed', 'pending', 'pending'][$i] ?? 'pending';
        }
        return $i % 2 === 0 ? 'confirmed' : 'pending';
    }
}
