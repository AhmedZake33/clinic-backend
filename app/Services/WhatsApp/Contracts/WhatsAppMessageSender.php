<?php

namespace App\Services\WhatsApp\Contracts;

interface WhatsAppMessageSender
{
    public function sendText(string $chatId, string $message, array $options = []): array;
}
