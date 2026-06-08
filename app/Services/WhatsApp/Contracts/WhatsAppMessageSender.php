<?php

namespace App\Services\WhatsApp\Contracts;

interface WhatsAppMessageSender
{
    public function sendText(string $chatId, string $message, array $options = []): array;

    public function sendImage(string $chatId, string $imageUrl, ?string $caption = null, array $options = []): array;
}
