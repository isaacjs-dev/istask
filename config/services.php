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

    /*
    | Gemini (Google AI Studio) — usado APENAS por exceção:
    |   A) quando o interpretador local não entende o comando;
    |   B) para corrigir ortografia/acentuação ao criar texto livre.
    | A chave NUNCA vai ao frontend — todas as chamadas passam pelo backend.
    */
    'gemini' => [
        'key'      => env('GEMINI_API_KEY'),
        'model'    => env('GEMINI_MODEL', 'gemini-3.5-flash'),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
    ],

    /*
    | Login com Google (OAuth via Laravel Socialite). Crie as credenciais no
    | Google Cloud Console → Credentials → OAuth client ID (tipo "Web application")
    | e use como "Authorized redirect URI" o valor de GOOGLE_REDIRECT_URI.
    | Sem CLIENT_ID configurado, o botão "Entrar com Google" não aparece (placeholder).
    */
    'google' => [
        'client_id'     => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect'      => env('GOOGLE_REDIRECT_URI', env('APP_URL').'/auth/google/callback'),
    ],

];
