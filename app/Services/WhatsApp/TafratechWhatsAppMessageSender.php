<?php

namespace App\Services\WhatsApp;

use App\Exceptions\WhatsApp\WhatsAppException;
use App\Services\WhatsApp\Contracts\WhatsAppMessageSender;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TafratechWhatsAppMessageSender implements WhatsAppMessageSender
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $token,
        private readonly int $timeout = 10,
    ) {
    }

    /**
     * @throws ConnectionException
     */
    public function sendText(string $chatId, string $message, array $options = []): array
    {
        return $this->postMessage('/api/send-message', [
            'phone' => $this->normalizeEgyptianMobilePhone($chatId),
            'message' => $message,
        ], $chatId, 'Tafratech message');
    }

    /**
     * @throws ConnectionException
     */
    public function sendImage(string $chatId, string $imageUrl, ?string $caption = null, array $options = []): array
    {
        return $this->postMessage('/api/send-image', array_filter([
            'phone' => $this->normalizeEgyptianMobilePhone($chatId),
            'imageUrl' => $imageUrl,
            'caption' => $caption,
        ], static fn ($value) => $value !== null && $value !== ''), $chatId, 'Tafratech image');
    }

    /**
     * @throws ConnectionException
     */
    private function postMessage(string $path, array $payload, string $originalChatId, string $logName): array
    {
        $this->ensureConfigured();

        try {
            $response = Http::timeout($this->timeout)
                ->acceptJson()
                ->asJson()
                ->withToken($this->token)
                ->post($this->endpoint($path), $payload);
        } catch (ConnectionException $exception) {
            Log::channel('whatsapp')->error("{$logName} connection failed.", [
                'base_url' => $this->baseUrl,
                'phone' => $payload['phone'] ?? null,
                'original_chat_id' => $originalChatId,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        if (! $response->successful()) {
            Log::channel('whatsapp')->warning("{$logName} rejected.", [
                'base_url' => $this->baseUrl,
                'phone' => $payload['phone'] ?? null,
                'original_chat_id' => $originalChatId,
                'status' => $response->status(),
                'response' => $response->json() ?? $response->body(),
            ]);
        }

        return [
            'successful' => $response->successful(),
            'status' => $response->status(),
            'response' => $response->json() ?? $response->body(),
        ];
    }

    private function ensureConfigured(): void
    {
        if (blank($this->token)) {
            throw WhatsAppException::providerNotConfigured();
        }
    }

    private function endpoint(string $path): string
    {
        return rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
    }

    private function normalizeEgyptianMobilePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        if ($digits === '') {
            return trim($phone);
        }

        if (str_starts_with($digits, '0020')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '200')) {
            $digits = '20' . substr($digits, 3);
        } elseif (str_starts_with($digits, '0')) {
            $digits = '20' . substr($digits, 1);
        } elseif (! str_starts_with($digits, '20')) {
            $digits = '20' . ltrim($digits, '0');
        }

        return $digits;
    }
}
