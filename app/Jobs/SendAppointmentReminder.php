<?php

namespace App\Jobs;

use App\Models\NotificationLog;
use App\Models\Reservation;
use App\Services\SmsService;
use App\Services\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendAppointmentReminder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [60, 300, 900];

    public function __construct(
        private Reservation $reservation,
        private string $channel,
        private string $messageBody,
    ) {}

    public function handle(): void
    {
        $reservation = $this->reservation->load(['client', 'doctor']);
        $client = $reservation->client;

        if (!$client || empty($client->phone)) {
            Log::warning("Reminder skipped: no client phone", ['reservation_id' => $reservation->id]);
            return;
        }

        // Create pending log entry
        $log = NotificationLog::create([
            'reservation_id' => $reservation->id,
            'client_id' => $client->id,
            'doctor_id' => $reservation->doctor_id,
            'channel' => $this->channel,
            'status' => 'pending',
            'phone_number' => $client->phone,
            'message_body' => $this->messageBody,
        ]);

        $result = match ($this->channel) {
            'whatsapp' => $this->sendWhatsApp($reservation),
            'sms' => $this->sendSms($client->phone, $this->messageBody),
        };

        $log->update([
            'status' => $result['success'] ? 'sent' : 'failed',
            'external_id' => $result['message_id'],
            'error_message' => $result['error'],
            'sent_at' => $result['success'] ? now() : null,
        ]);
    }

    private function sendWhatsApp(Reservation $reservation): array
    {
        $service = app(WhatsAppService::class);
        $settings = $reservation->doctor->notificationSetting;
        $templateName = $settings?->whatsapp_template_name ?? 'appointment_reminder';

        return $service->sendTemplateMessage(
            $reservation->client->phone,
            $templateName,
            [
                'client_name' => $reservation->client->name,
                'doctor_name' => $reservation->doctor->name,
                'time' => $reservation->appointment_date->format('h:i A'),
            ],
        );
    }

    private function sendSms(string $phone, string $message): array
    {
        $service = app(SmsService::class);
        return $service->send($phone, $message);
    }
}
