<?php

namespace App\Http\Traits;

use Illuminate\Http\Request;

trait NormalizesPhoneNumbers
{
    protected function normalizePhoneInputs(Request $request): void
    {
        $request->merge([
            'phone' => $this->normalizePhoneNumber(
                $request->input('phone'),
                $request->input('phone_country_code') ?: $request->input('country_code')
            ),
            'whatsapp_number' => $this->normalizePhoneNumber(
                $request->input('whatsapp_number'),
                $request->input('whatsapp_country_code') ?: $request->input('country_code')
            ),
        ]);
    }

    protected function normalizePhoneNumber($number, $countryCode = null): ?string
    {
        if (!filled($number)) {
            return null;
        }

        $rawNumber = trim((string) $number);
        $digits = preg_replace('/\D+/', '', $rawNumber);

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($rawNumber, '+')) {
            return '+' . ltrim($digits, '0');
        }

        $prefixDigits = preg_replace('/\D+/', '', (string) $countryCode);
        if ($prefixDigits) {
            return '+' . ltrim($prefixDigits, '0') . ltrim($digits, '0');
        }

        return $digits;
    }
}
