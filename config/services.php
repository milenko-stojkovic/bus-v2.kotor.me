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

    'bank' => [
        'driver' => env('BANK_DRIVER', 'fake'), // fake | bankart
    ],

    'bankart' => [
        'api_url' => env('BANKART_API_URL'),
        'api_key' => env('BANKART_API_KEY'),
        'username' => env('BANKART_USERNAME'),
        'password' => env('BANKART_PASSWORD'),
        'shared_secret' => env('BANKART_SHARED_SECRET'),
        'signature_enabled' => filter_var(env('BANKART_SIGNATURE_ENABLED', true), FILTER_VALIDATE_BOOL),
        'send_customer' => filter_var(env('BANKART_SEND_CUSTOMER', true), FILTER_VALIDATE_BOOL),
        'billing_address1' => env('BANKART_BILLING_ADDRESS1'),
        'billing_city' => env('BANKART_BILLING_CITY'),
        'billing_postcode' => env('BANKART_BILLING_POSTCODE'),
    ],

    'fiscalization' => [
        'driver' => env('FISCALIZATION_DRIVER', 'fake'), // fake | real
        // Optional helper for local QA: scenario passed to fake fiscal endpoints (e.g. deposit_missing, provider_down, tax_server_error).
        'fake_scenario' => env('FISCAL_FAKE_SCENARIO'),
    ],

    'fiscal' => [
        'api_url' => env('FISCAL_API_URL'),
        'api_token' => env('FISCAL_API_TOKEN'),
        'enu_identifier' => env('FISCAL_ENU_IDENTIFIER'),
        'user_code' => env('FISCAL_USER_CODE'),
        'user_name' => env('FISCAL_USER_NAME'),
        'seller_name' => env('FISCAL_SELLER_NAME'),
        'seller_id_type' => env('FISCAL_SELLER_ID_TYPE', 'TIN'),
        'seller_id_value' => env('FISCAL_SELLER_ID_VALUE', env('FISCAL_SELLER_TIN')),
        'seller_address' => env('FISCAL_SELLER_ADDRESS'),
        'tax_rate' => (int) env('FISCAL_TAX_RATE', 0),
        'driver' => env('FISCALIZATION_DRIVER', 'real'),
    ],

    /*
    |--------------------------------------------------------------------------
    | MEGA (external private file archive — server-side only)
    |--------------------------------------------------------------------------
    */
    'mega' => [
        'email' => env('MEGA_EMAIL'),
        'password' => env('MEGA_PASSWORD'),
        'base_folder' => env('MEGA_BASE_FOLDER', 'bus.kotor'),
        'node_binary' => env('MEGA_NODE_BINARY'),
        'user_agent' => env('MEGA_USER_AGENT', 'BusKotorArchive/1.0'),
    ],

];
