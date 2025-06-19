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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'vertex_ai' => [
        'project_id' => env('VERTEX_AI_PROJECT_ID'),
        'location' => env('VERTEX_AI_LOCATION', 'europe-west8'),
        'endpoint_id' => env('VERTEX_AI_ENDPOINT_ID'),
        'key_file_path' => env('VERTEX_AI_KEY_FILE_PATH', base_path('keys/service-account.json')),
        'bucket_name' => env('VERTEX_AI_BUCKET_NAME'),
        'dataset_path' => env('VERTEX_AI_DATASET_PATH'),
    ],
];
