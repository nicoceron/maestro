<?php

$invitationTokenSecret = trim((string) env('INVITATION_TOKEN_SECRET', ''));

if ($invitationTokenSecret === '' && in_array((string) env('APP_ENV', 'production'), ['local', 'testing'], true)) {
    $invitationTokenSecret = (string) env('APP_KEY', '');
}

return [

    'frontend' => [
        'url' => env('FRONTEND_URL', 'http://localhost:3000'),
    ],

    'invitations' => [
        'expires_days' => (int) env('INVITATION_EXPIRES_DAYS', 7),
        'resend_cooldown_seconds' => (int) env('INVITATION_RESEND_COOLDOWN_SECONDS', 60),
        'token_secret' => $invitationTokenSecret,
    ],

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

];
