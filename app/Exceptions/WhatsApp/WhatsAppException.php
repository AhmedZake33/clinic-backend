<?php

namespace App\Exceptions\WhatsApp;

use RuntimeException;

class WhatsAppException extends RuntimeException
{
    public static function providerNotConfigured(): self
    {
        return new self('WhatsApp provider is not configured.');
    }

    public static function unsupportedDriver(string $driver): self
    {
        return new self("Unsupported WhatsApp driver [{$driver}].");
    }
}
