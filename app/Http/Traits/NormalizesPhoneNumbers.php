<?php

namespace App\Http\Traits;

use Illuminate\Http\Request;

trait NormalizesPhoneNumbers
{
    protected function requireCountryCodeForPhoneInputs(Request $request): void
    {
        $errors = [];

        if ($this->phoneNeedsCountryCode($request->input('phone'), $request->input('phone_country_code'))) {
            $errors['phone_country_code'] = ['Country code is required when entering a mobile number.'];
        }

        if ($this->phoneNeedsCountryCode($request->input('whatsapp_number'), $request->input('whatsapp_country_code'))) {
            $errors['whatsapp_country_code'] = ['Country code is required when entering a WhatsApp number.'];
        }

        if ($errors) {
            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        }
    }

    protected function normalizePhoneInputs(Request $request): void
    {
        $this->requireCountryCodeForPhoneInputs($request);

        $request->merge([
            'phone' => $this->normalizePhoneNumber(
                $request->input('phone'),
                $request->input('phone_country_code')
            ),
            'whatsapp_number' => $this->normalizePhoneNumber(
                $request->input('whatsapp_number'),
                $request->input('whatsapp_country_code')
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

    private function phoneNeedsCountryCode($number, $countryCode = null): bool
    {
        if (!filled($number)) {
            return false;
        }

        $rawNumber = trim((string) $number);

        if ($rawNumber === '' || str_starts_with($rawNumber, '+')) {
            return false;
        }

        return !filled($countryCode);
    }
}
