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
        // `chat_model` is the agent target; `chat_backend_model` pins the LLM behind it via the
        // x-openclaw-model header. Leave the second empty to use whatever the agent is set to.
        'chat_model' => env('AI_CHAT_TARGET', 'openclaw/main'),
        'chat_backend_model' => env('AI_CHAT_BACKEND_MODEL', ''),
    ],

    // Ad platforms. Campaigns run on Codemenschen's OWN ad accounts (one token set, no per-client
    // OAuth), but the CUSTOMER pays: the monthly ad budget they bought is the hard spend cap set
    // on the platform campaign. Empty token => that platform is simply unavailable, not an error.
    'ads' => [
        'meta' => [
            'token' => env('META_ADS_TOKEN'),            // long-lived System User token
            'ad_account_id' => env('META_ADS_ACCOUNT_ID'), // act_1234567890
            'page_id' => env('META_ADS_PAGE_ID'),
            'api_version' => env('META_ADS_API_VERSION', 'v21.0'),
        ],
        'google' => [
            'developer_token' => env('GOOGLE_ADS_DEVELOPER_TOKEN'), // Google approves this by hand
            'customer_id' => env('GOOGLE_ADS_CUSTOMER_ID'),         // 10 digits, no dashes
            'login_customer_id' => env('GOOGLE_ADS_LOGIN_CUSTOMER_ID'),
            'client_id' => env('GOOGLE_ADS_CLIENT_ID'),
            'client_secret' => env('GOOGLE_ADS_CLIENT_SECRET'),
            'refresh_token' => env('GOOGLE_ADS_REFRESH_TOKEN'),
            'api_version' => env('GOOGLE_ADS_API_VERSION', 'v18'),
        ],
    ],

    'media' => [
        // Marketing clips rendered by ops/make-video.py. Served only to signed-in customers.
        'videos_path' => env('MEDIA_VIDEOS_PATH', '/media/videos'),   // container path; host side is /var/appwerk-media/videos
        'uploads_path' => env('MEDIA_UPLOADS_PATH', '/media/uploads'), // images the customer uploads, and render scratch
        // The shared photo library. Same directory ops/library.sh works on, bind-mounted from
        // /var/appwerk-media/library on the host, so the shell and the app see one catalog.
        'library_path' => env('MEDIA_LIBRARY_PATH', '/media/library'),
        // Screenshots of designs to aim at. References only: shown to the model while it writes,
        // never served to anyone, never embedded in anything that leaves the building.
        'design_refs_path' => env('MEDIA_DESIGN_REFS_PATH', '/media/design-refs'),
        // The reference library: app screens collected to study, never shipped. Read-only, and
        // the app only ever browses it; the labelling script on the host is what writes it.
        'design_library_path' => env('MEDIA_DESIGN_LIBRARY_PATH', '/media/design-library'),
    ],

    // Free photographs for prototypes. A prototype is thrown away in a week, so a real stock
    // photo is both cheaper and more honest than a generated one; generation stays for the paid
    // ad pipeline. Empty key means the whole source is simply skipped.
    'stock' => [
        'pexels_key' => env('PEXELS_API_KEY'),
    ],

    // The browser that looks at a generated page before a visitor does. Both paths are inside the
    // api image; if either is missing the audit reports itself skipped and the build carries on.
    'qa' => [
        'script' => env('QA_SCRIPT_PATH', base_path('tools/qa-page.cjs')),
        'node' => env('QA_NODE_BIN'),
    ],

    'openclaw' => [
        'hook_url' => env('OPENCLAW_HOOK_URL'),
        'hook_token' => env('OPENCLAW_HOOK_TOKEN'),
    ],

];
