<?php

namespace App\Console\Commands;

use App\Jobs\SendAppointmentReminder;
use App\Models\DoctorNotificationSetting;
use App\Models\NotificationLog;
use App\Models\Reservation;
use Illuminate\Console\Command;

class SendAppointmentReminders extends Command
{
    protected $signature = 'reminders:send';
    protected $description = 'Send appointment reminders to patients 24 hours before their appointments';

    public function handle(): int
    {
        // Get all doctors who have at least one channel enabled
        $settings = DoctorNotificationSetting::where(function ($q) {
            $q->where('sms_enabled', true)
              ->orWhere('whatsapp_enabled', true);
        })->get();

        if ($settings->isEmpty()) {
            $this->info('No doctors have reminders enabled.');
            return 0;
        }

        $smsCount = 0;
        $whatsappCount = 0;

        foreach ($settings as $setting) {
            $hoursBeforeMin = $setting->reminder_hours_before - 1; // 23h
            $hoursBeforeMax = $setting->reminder_hours_before + 1; // 25h

            // Find upcoming reservations within the reminder window
            $reservations = Reservation::with(['client', 'doctor'])
                ->where('doctor_id', $setting->doctor_id)
                ->whereIn('status', ['pending', 'confirmed'])
                ->whereBetween('appointment_date', [
                    now()->addHours($hoursBeforeMin),
                    now()->addHours($hoursBeforeMax),
                ])
                ->get();

        //         $this->info("Doctor ID: {$setting->doctor_id}, Reminder Window: " . $reservations);

        // return 0;
                // $this->info("now is " . now());
            // return 0;
            foreach ($reservations as $reservation) {
                if (!$reservation->client || empty($reservation->client->phone)) {
                    continue;
                }

                $messageBody = $this->buildMessage(
                    $setting->getEffectiveSmsTemplate(),
                    $reservation->client->name,
                    $reservation->doctor->name,
                    $reservation->appointment_date->format('h:i A'),
                );

                // Send SMS if enabled and not already sent
                if ($setting->sms_enabled) {
                    $alreadySent = NotificationLog::where('reservation_id', $reservation->id)
                        ->where('channel', 'sms')
                        ->whereIn('status', ['pending', 'sent', 'delivered'])
                        ->exists();

                    if (!$alreadySent) {
                        SendAppointmentReminder::dispatch($reservation, 'sms', $messageBody);
                        $smsCount++;
                    }
                }

                // Send WhatsApp if enabled and not already sent
                if ($setting->whatsapp_enabled) {
                    $alreadySent = NotificationLog::where('reservation_id', $reservation->id)
                        ->where('channel', 'whatsapp')
                        ->whereIn('status', ['pending', 'sent', 'delivered'])
                        ->exists();

                    if (!$alreadySent) {
                        SendAppointmentReminder::dispatch($reservation, 'whatsapp', $messageBody);
                        $whatsappCount++;
                    }
                }
            }
        }

        $this->info("Dispatched {$smsCount} SMS and {$whatsappCount} WhatsApp reminders.");
        return 0;
    }

    private function buildMessage(string $template, string $clientName, string $doctorName, string $time): string
    {
        return str_replace(
            ['{client_name}', '{doctor_name}', '{time}'],
            [$clientName, $doctorName, $time],
            $template,
        );
    }
}
