<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    private string $accountSid;
    private string $authToken;
    private string $fromNumber;

    public function __construct()
    {
        $this->accountSid = config('services.twilio.account_sid');
        $this->authToken = config('services.twilio.auth_token');
        $this->fromNumber = config('services.twilio.whatsapp_from');
    }

    /**
     * Send a WhatsApp message via Twilio API.
     *
     * For template messages, Twilio uses ContentSid. For freeform messages
     * (within the 24h session window or using pre-approved templates),
     * you can send the body directly.
     */
    public function sendTemplateMessage(string $to, string $templateName, array $parameters): array
    {
        $url = "https://api.twilio.com/2010-04-01/Accounts/{$this->accountSid}/Messages.json";

        // Build the message body from template parameters
        $body = $this->buildMessageBody($parameters);

        $payload = [
            'From' => "whatsapp:{$this->fromNumber}",
            'To' => 'whatsapp:' . $this->formatPhoneNumber($to),
            'Body' => $body,
        ];

        // If a Twilio Content SID is configured for the template, use it instead
        $contentSid = config('services.twilio.whatsapp_content_sid');
        if ($contentSid) {
            $payload = [
                'From' => "whatsapp:{$this->fromNumber}",
                'To' => 'whatsapp:' . $this->formatPhoneNumber($to),
                'ContentSid' => $contentSid,
                'ContentVariables' => json_encode($parameters),
            ];
        }

        try {
            $response = Http::asForm()
                ->withBasicAuth($this->accountSid, $this->authToken)
                ->timeout(30)
                ->post($url, $payload);

                Log::info('Twilio WhatsApp API request', ['payload' => $payload, 'response_status' => $response->status()]);

            if ($response->successful() || $response->status() === 201) {
                $data = $response->json();
                return [
                    'success' => true,
                    'message_id' => $data['sid'] ?? null,
                    'error' => null,
                ];
            }

            $errorData = $response->json();
            $errorMsg = $errorData['message'] ?? 'Unknown Twilio WhatsApp error';
            Log::error('Twilio WhatsApp API error', ['response' => $errorData]);

            return [
                'success' => false,
                'message_id' => null,
                'error' => $errorMsg,
            ];
        } catch (\Exception $e) {
            Log::error('Twilio WhatsApp send failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message_id' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function buildMessageBody(array $parameters): string
    {
        $clientName = $parameters['client_name'] ?? '';
        $doctorName = $parameters['doctor_name'] ?? '';
        $time = $parameters['time'] ?? '';

        return "مرحباً {$clientName}، نذكرك بموعدك غداً في عيادة د. {$doctorName} الساعة {$time}. للإلغاء أو التعديل يرجى التواصل معنا.";
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
