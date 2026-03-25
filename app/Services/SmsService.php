<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsService
{
    private string $accountSid;
    private string $authToken;
    private string $fromNumber;

    public function __construct()
    {
        $this->accountSid = config('services.twilio.account_sid');
        $this->authToken = config('services.twilio.auth_token');
        $this->fromNumber = config('services.twilio.sms_from');
    }

    /**
     * Send an SMS via Twilio API.
     */
    public function send(string $to, string $message): array
    {
        if (empty($this->accountSid) || empty($this->authToken)) {
            return [
                'success' => false,
                'message_id' => null,
                'error' => 'Twilio credentials not configured',
            ];
        }

        $url = "https://api.twilio.com/2010-04-01/Accounts/{$this->accountSid}/Messages.json";
        $phone = $this->formatPhoneNumber($to);

        try {
            $response = Http::asForm()
                ->withBasicAuth($this->accountSid, $this->authToken)
                ->timeout(30)
                ->post($url, [
                    'From' => $this->fromNumber,
                    'To' => $phone,
                    'Body' => $message,
                ]);

            if ($response->successful() || $response->status() === 201) {
                $data = $response->json();
                return [
                    'success' => true,
                    'message_id' => $data['sid'] ?? null,
                    'error' => null,
                ];
            }

            $errorMsg = $response->json('message') ?? 'Twilio SMS error';
            Log::error('Twilio SMS API error', ['response' => $response->json()]);

            return [
                'success' => false,
                'message_id' => null,
                'error' => $errorMsg,
            ];
        } catch (\Exception $e) {
            Log::error('Twilio SMS send failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message_id' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Ensure phone number is in E.164 format.
     */
    private function formatPhoneNumber(string $phone): string
    {
        $phone = preg_replace('/[^0-9+]/', '', $phone);
        if (!str_starts_with($phone, '+')) {
            $phone = '+' . $phone;
        }
        return $phone;
    }
}
