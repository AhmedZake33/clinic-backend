<?php

namespace App\Console\Commands;

use App\Models\Reservation;
use App\Services\WhatsApp\Contracts\WhatsAppMessageSender;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendReservationWhatsAppReminders extends Command
{
    protected $signature = 'reservations:send-whatsapp-reminders
        {--hours=24 : Send reminders this many hours before appointment}
        {--window=60 : Reminder lookup window in minutes}
        {--dry-run : Show matching reservations without sending messages}';

    protected $description = 'Send WhatsApp reminders to clients before their reservation appointment time.';

    public function handle(WhatsAppMessageSender $sender): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $window = max(1, (int) $this->option('window'));
        $dryRun = (bool) $this->option('dry-run');

        $from = now()->addHours($hours);
        $to = $from->copy()->addMinutes($window);

        $reservations = Reservation::query()
            ->with(['client', 'doctor'])
            ->whereNull('whatsapp_reminder_sent_at')
            ->whereIn('status', ['pending', 'confirmed'])
            ->whereBetween('appointment_date', [$from, $to])
            ->orderBy('appointment_date')
            ->get();

        if ($reservations->isEmpty()) {
            $this->info('No reservation WhatsApp reminders are due.');

            return self::SUCCESS;
        }

        $sent = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($reservations as $reservation) {
            $chatId = $this->chatIdFor($reservation);

            if (! $chatId) {
                $skipped++;
                $this->warn("Reservation {$reservation->id} skipped: no client WhatsApp/phone number.");
                continue;
            }

            $message = $this->messageFor($reservation);

            if ($dryRun) {
                $this->line("Dry run: reservation {$reservation->id} -> {$chatId}: {$message}");
                continue;
            }

            try {
                $result = $sender->sendText($chatId, $message);

                if (! $result['successful']) {
                    $failed++;
                    $this->error("Reservation {$reservation->id} failed with provider status {$result['status']}.");
                    Log::channel('whatsapp')->warning('Reservation WhatsApp reminder rejected by provider.', [
                        'reservation_id' => $reservation->id,
                        'status' => $result['status'],
                        'response' => $result['response'],
                    ]);
                    continue;
                }

                $reservation->forceFill([
                    'whatsapp_reminder_sent_at' => now(),
                ])->save();

                $sent++;
                $this->info("Reservation {$reservation->id} reminder sent.");
            } catch (\Throwable $exception) {
                $failed++;
                $this->error("Reservation {$reservation->id} failed: {$exception->getMessage()}");
                Log::channel('whatsapp')->error('Reservation WhatsApp reminder failed.', [
                    'reservation_id' => $reservation->id,
                    'exception' => $exception,
                ]);
            }
        }

        $this->info("Done. Sent: {$sent}. Failed: {$failed}. Skipped: {$skipped}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function chatIdFor(Reservation $reservation): ?string
    {
        $raw = $reservation->client?->whatsapp_number ?: $reservation->client?->phone;

        if (! $raw) {
            return null;
        }

        $chatId = preg_replace('/[^\d@.]/', '', $raw);

        return $chatId !== '' ? $chatId : null;
    }

    private function messageFor(Reservation $reservation): string
    {
        $appointment = Carbon::parse($reservation->appointment_date);

        $replacements = [
            '{client}' => $reservation->client?->name ?? 'Client',
            '{doctor}' => $reservation->doctor?->name ?? 'the doctor',
            '{date}' => $appointment->format('Y-m-d'),
            '{time}' => $appointment->format('h:i A'),
        ];

        return strtr(config('services.whatsapp.reservation_reminder_message'), $replacements);
    }
}
