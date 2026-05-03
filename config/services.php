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

    'integration' => [
        'read_api_token' => env('INTEGRATION_READ_API_TOKEN'),
    ],

    'sap' => [
        'http_timeout' => (int) env('SAP_HTTP_TIMEOUT', 60),
        'post_timeout' => (int) env('SAP_POST_TIMEOUT', 120),
        'login_timeout' => (int) env('SAP_LOGIN_TIMEOUT', 30),
        'logout_timeout' => (int) env('SAP_LOGOUT_TIMEOUT', 10),
        'duplicate_invoice_scan_limit' => (int) env('SAP_DUPLICATE_INVOICE_SCAN_LIMIT', 2000),
    ],

];
