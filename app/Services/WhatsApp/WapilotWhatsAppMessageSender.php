<?php

namespace App\Services\WhatsApp;

use App\Exceptions\WhatsApp\WhatsAppException;
use App\Services\WhatsApp\Contracts\WhatsAppMessageSender;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WapilotWhatsAppMessageSender implements WhatsAppMessageSender
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $instanceId,
        private readonly ?string $token,
        private readonly int $timeout = 10,
    ) {
    }

    /**
     * @throws ConnectionException
     */
    public function sendText(string $chatId, string $message, array $options = []): array
    {
        $this->ensureConfigured();

        $payload = array_filter([
            'chat_id' => $chatId,
            'text' => $message,
            'priority' => $options['priority'] ?? null,
            'send_at' => $options['send_at'] ?? null,
        ], static fn ($value) => $value !== null && $value !== '');

        try {
            $response = Http::timeout($this->timeout)
                ->acceptJson()
                ->asJson()
                ->withHeaders(['token' => $this->token])
                ->post($this->endpoint("/{$this->instanceId}/send-message"), $payload);
        } catch (ConnectionException $exception) {
            Log::channel('whatsapp')->error('WAPilot connection failed.', [
                'base_url' => $this->baseUrl,
                'instance_id' => $this->instanceId,
                'chat_id' => $chatId,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        if (! $response->successful()) {
            Log::channel('whatsapp')->warning('WAPilot message rejected.', [
                'base_url' => $this->baseUrl,
                'instance_id' => $this->instanceId,
                'chat_id' => $chatId,
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
        if (blank($this->instanceId) || blank($this->token)) {
            throw WhatsAppException::providerNotConfigured();
        }
    }

    private function endpoint(string $path): string
    {
        return rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
    }
}
