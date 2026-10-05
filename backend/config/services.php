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

    // Philippine SMS gateway for deadline alerts. Leave the key empty to log
    // messages instead of sending them.
    // ClamAV (clamd) scans every uploaded file before it is stored.
    // fail_open=false: if the scanner is down, uploads are refused rather
    // than stored unscanned.
    'clamav' => [
        'enabled' => (bool) env('CLAMAV_ENABLED', false),
        'host' => env('CLAMAV_HOST', '127.0.0.1'),
        'port' => (int) env('CLAMAV_PORT', 3310),
        'timeout' => (int) env('CLAMAV_TIMEOUT', 30),
        'fail_open' => (bool) env('CLAMAV_FAIL_OPEN', false),
    ],

    // The matter assistant (Anthropic Claude). Firms must also opt in under
    // Firm Settings, because matter documents are sent to Anthropic.
    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-opus-5-5'),
        'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 4096),
        // Roughly 100k tokens of case file per question.
        'context_chars' => (int) env('ANTHROPIC_CONTEXT_CHARS', 400000),
    ],

    // OCR for scanned files (Tesseract + Poppler's pdftoppm), run by the
    // queue worker. "fil" is Tesseract's Filipino language data.
    'ocr' => [
        'enabled' => (bool) env('OCR_ENABLED', false),
        'tesseract' => env('TESSERACT_PATH', 'tesseract'),
        'pdftoppm' => env('PDFTOPPM_PATH', 'pdftoppm'),
        'languages' => env('OCR_LANGUAGES', 'eng+fil'),
        'max_pages' => (int) env('OCR_MAX_PAGES', 30),
        'timeout' => (int) env('OCR_TIMEOUT', 300),
    ],

    'semaphore' => [
        'api_key' => env('SEMAPHORE_API_KEY'),
        'sender_name' => env('SEMAPHORE_SENDER_NAME', 'SEMAPHORE'),
    ],

    // PayMongo online payments. Leave the secret key empty to turn online
    // payment off. The webhook secret comes from the webhook you register
    // for https://<your-domain>/api/webhooks/paymongo (checkout_session.payment.paid).
    'paymongo' => [
        'secret_key' => env('PAYMONGO_SECRET_KEY'),
        'webhook_secret' => env('PAYMONGO_WEBHOOK_SECRET'),
        'payment_methods' => array_values(array_filter(explode(',', (string) env('PAYMONGO_PAYMENT_METHODS', 'card,gcash,paymaya,qrph')))),
    ],

    // Email to matter. Point your inbound email provider (SendGrid Inbound
    // Parse with "raw", a Mailgun route forwarding to a .../mime URL, or
    // anything that posts the raw message) at
    // https://inbound:<secret>@<your-domain>/api/webhooks/inbound-email
    // (or send the secret in an X-Inbound-Secret header).
    // Each matter gets its own address: <local>+<matter code>@<domain>.
    'inbound_email' => [
        'address' => env('INBOUND_EMAIL_ADDRESS'),   // e.g. files@inbound.yourfirm.ph
        'secret' => env('INBOUND_EMAIL_SECRET'),
        // The provider's name in the Authentication-Results headers it adds
        // (e.g. mx.sendgrid.net); only its SPF/DKIM/DMARC results are trusted.
        'authserv_id' => env('INBOUND_EMAIL_AUTHSERV_ID'),
        'max_kilobytes' => (int) env('INBOUND_EMAIL_MAX_KILOBYTES', 23 * 1024), // under PHP's 24 MB post limit
    ],

    // Phone and browser notifications (Web Push). Generate the pair once with
    // "php artisan ops:vapid-keys"; changing it later means everyone turns
    // notifications on again.
    'webpush' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'subject' => env('VAPID_SUBJECT'), // mailto: or https: contact for push services
    ],

    // Electronic filing packages: the largest single PDF courts accept by
    // e-mail or portal (check your court's current rule).
    // Electronic invoicing (BIR EIS). "record" keeps each e-invoice on file and
    // sends nothing (until a provider is chosen); "http" posts it, signed, to a
    // provider's HTTPS endpoint. deadline_days: how soon an e-invoice must reach
    // the BIR after the invoice is issued (check the current BIR regulations).
    'einvoicing' => [
        'driver' => env('EINVOICE_DRIVER', 'record'),
        'endpoint' => env('EINVOICE_ENDPOINT'),
        'token' => env('EINVOICE_TOKEN'),
        'secret' => env('EINVOICE_SECRET'),
        'deadline_days' => (int) env('EINVOICE_DEADLINE_DAYS', 3),
    ],

    // Bulk import of documents (Firm Settings > Import data): a ZIP, uploaded in
    // 10 MB pieces, with at most this many files and this much unpacked.
    'document_imports' => [
        'max_mb' => (int) env('DOCUMENT_IMPORT_MAX_MB', 2048),
        'max_files' => (int) env('DOCUMENT_IMPORT_MAX_FILES', 5000),
        'max_unpacked_mb' => (int) env('DOCUMENT_IMPORT_MAX_UNPACKED_MB', 10240),
    ],

    'efiling' => [
        'max_mb' => (int) env('EFILING_MAX_MB', 25),
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
