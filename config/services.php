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

    'google_translate' => [
        'key' => env('GOOGLE_TRANSLATE_API_KEY'),
        'timeout' => env('GOOGLE_TRANSLATE_TIMEOUT', 5),
        'allow_in_testing' => env('GOOGLE_TRANSLATE_ALLOW_IN_TESTING', false),
    ],

    'nova_poshta' => [
        'api_key' => env('NOVA_POSHTA_API_KEY'),
        'api_url' => env('NOVA_POSHTA_API_URL', 'https://api.novaposhta.ua/v2.0/json/'),
        'print_url' => env('NOVA_POSHTA_PRINT_URL', 'https://my.novaposhta.ua/orders/printDocument'),
        'timeout' => env('NOVA_POSHTA_TIMEOUT', 15),
        'connect_timeout' => env('NOVA_POSHTA_CONNECT_TIMEOUT', 30),
        'sender_city_ref' => env('NOVA_POSHTA_SENDER_CITY_REF'),
        'sender_ref' => env('NOVA_POSHTA_SENDER_REF'),
        'sender_address_ref' => env('NOVA_POSHTA_SENDER_ADDRESS_REF'),
        'sender_contact_ref' => env('NOVA_POSHTA_SENDER_CONTACT_REF'),
        'sender_phone' => env('NOVA_POSHTA_SENDER_PHONE'),
        'payer_type' => env('NOVA_POSHTA_PAYER_TYPE', 'Recipient'),
        'payment_method' => env('NOVA_POSHTA_PAYMENT_METHOD', 'Cash'),
        'default_weight' => env('NOVA_POSHTA_DEFAULT_WEIGHT', 1),
        'default_seats_amount' => env('NOVA_POSHTA_DEFAULT_SEATS_AMOUNT', 1),
        'cargo_description' => env('NOVA_POSHTA_CARGO_DESCRIPTION', "\u{0430}\u{0432}\u{0442}\u{043E}\u{0437}\u{0430}\u{043F}\u{0447}\u{0430}\u{0441}\u{0442}\u{0438}\u{043D}\u{0438}"),
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

];
