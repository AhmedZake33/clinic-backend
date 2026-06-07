<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'whatsapp' => [
        'driver' => env('WHATSAPP_DRIVER', 'wapilot'),
        'reservation_reminder_message' => env(
            'WHATSAPP_RESERVATION_REMINDER_MESSAGE',
            'Hello {client}, this is a reminder for your appointment with Dr. {doctor} on {date} at {time}.'
        ),

        'wapilot' => [
            'base_url' => env('WAPILOT_BASE_URL', 'https://api.wapilot.net'),
            'instance_id' => env('WAPILOT_INSTANCE_ID'),
            'token' => env('WAPILOT_TOKEN'),
            'timeout' => (int) env('WAPILOT_TIMEOUT', 10),
        ],

        'tafratech' => [
            'base_url' => env('TAFRATECH_WHATSAPP_BASE_URL', 'https://whatsapp.tafratech.com'),
            'token' => env('TAFRATECH_WHATSAPP_TOKEN'),
            'timeout' => (int) env('TAFRATECH_WHATSAPP_TIMEOUT', 10),
            'reservation_reminder_message' => env('TAFRATECH_MESSAGE'),
        ],
    ],

];
