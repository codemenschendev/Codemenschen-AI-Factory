<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),

    'admin_email' => env('ADMIN_EMAIL'),

    'worker' => [
        'url' => env('WORKER_URL', 'http://worker:8300'),
        'token' => env('WORKER_TOKEN'),
        'artifacts_path' => env('ARTIFACTS_PATH', '/artifacts'),
    ],

    // Image sidecar (manager.codemenschen.at/openclaw-worker/image-service.mjs), running as the
    // `openclaw` user on the host. The OpenClaw gateway has no images/generations endpoint, so
    // the sidecar wraps `openclaw infer image generate` as HTTP. Same service the giftcard and
    // CookCam stacks already use — Codemenschen pays OpenAI for the renders, so `quality` is a
    // real cost lever: keep it at medium unless someone asks for better.
    'ai_image' => [
        'base_url' => env('AI_IMAGE_SERVICE_BASE_URL', 'http://172.17.0.1:18790'),
        'token' => env('AI_IMAGE_SERVICE_TOKEN'),
        'timeout' => (int) env('AI_IMAGE_SERVICE_TIMEOUT', 180),
        'quality' => env('AI_IMAGE_QUALITY', 'medium'),
        'model' => env('AI_IMAGE_MODEL'),
    ],

    'media' => [
        // Marketing clips rendered by ops/make-video.py. Served only to signed-in customers.
        'videos_path' => env('MEDIA_VIDEOS_PATH', '/media/videos'),   // container path; host side is /var/appwerk-media/videos
        'uploads_path' => env('MEDIA_UPLOADS_PATH', '/media/uploads'), // images the customer uploads, and render scratch
    ],

    'openclaw' => [
        'hook_url' => env('OPENCLAW_HOOK_URL'),
        'hook_token' => env('OPENCLAW_HOOK_TOKEN'),
    ],

];
