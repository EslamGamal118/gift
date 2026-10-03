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

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | 4Jawaly SMS Provider
    |--------------------------------------------------------------------------
    */

    'forjawaly' => [
        'url'    => env('FORJAWALY_API_URL', 'https://api-sms.4jawaly.com/api/v1/account/area/sms/send'),
        'key'    => env('FORJAWALY_API_KEY'),
        'secret' => env('FORJAWALY_API_SECRET'),
        'sender' => env('FORJAWALY_SENDER'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment Gateways
    |--------------------------------------------------------------------------
    */

    'alrajhi' => [
        'base_url'      => env('ALRAJHI_BASE_URL'),
        'transportal_id' => env('ALRAJHI_TRANSPORTAL_ID'),
        'password'      => env('ALRAJHI_PASSWORD'),
    ],

    'tamara' => [
        'enabled'             => (bool) env('TAMARA_ENABLED', true),
        'base_url'            => env('TAMARA_BASE_URL', 'https://api-sandbox.tamara.co'),
        'api_token'           => env('TAMARA_API_TOKEN'),
        'notification_token'  => env('TAMARA_NOTIFICATION_TOKEN'),
        'currency'            => env('TAMARA_CURRENCY', 'SAR'),
        'country_code'        => env('TAMARA_COUNTRY_CODE', 'SA'),
        'locale'              => env('TAMARA_LOCALE', 'ar_SA'),
        'default_instalments' => (int) env('TAMARA_DEFAULT_INSTALMENTS', 3),
        'platform'            => env('TAMARA_PLATFORM', env('APP_NAME', 'Laravel')),
    ],

    'tabby' => [
        'enabled'       => (bool) env('TABBY_ENABLED', true),
        // https://api.tabby.ai for both sandbox and production (keys decide the mode).
        'base_url'      => env('TABBY_BASE_URL', 'https://api.tabby.ai'),
        'public_key'    => env('TABBY_PUBLIC_KEY'),
        'secret_key'    => env('TABBY_SECRET_KEY'),
        'merchant_code' => env('TABBY_MERCHANT_CODE'),
        'currency'      => env('TABBY_CURRENCY', 'SAR'),
        'lang'          => env('TABBY_LANG', 'ar'),
        // Capture the payment automatically once Tabby authorises it.
        'auto_capture'  => (bool) env('TABBY_AUTO_CAPTURE', true),
        // Custom header Tabby sends with every webhook (set when the webhook is registered).
        'webhook_header' => env('TABBY_WEBHOOK_HEADER', 'X-Tabby-Auth'),
        'webhook_secret' => env('TABBY_WEBHOOK_SECRET'),
        'is_test'       => (bool) env('TABBY_IS_TEST', true),
        // Optional mobile deep link the return endpoints redirect to (query: order, outcome).
        'app_return_url' => env('TABBY_APP_RETURN_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Firebase Cloud Messaging (HTTP v1)
    |--------------------------------------------------------------------------
    |
    | Path to the service-account JSON downloaded from the Firebase console.
    | The project id is read from that file unless overridden here.
    |
    */

    'fcm' => [
        'enabled'     => (bool) env('FCM_ENABLED', true),
        'credentials' => env('FCM_CREDENTIALS', storage_path('app/firebase/service-account.json')),
        'project_id'  => env('FCM_PROJECT_ID'),
    ],

    /*
    |--------------------------------------------------------------------------
    | WhatsApp (Evolution API)
    |--------------------------------------------------------------------------
    |
    | Text messages go to POST {url}/message/sendText/{instance} with the
    | `apikey` header. Used to deliver online gifts to their recipients.
    |
    */

    'evolution' => [
        'enabled'  => (bool) env('EVOLUTION_ENABLED', true),
        'url'      => env('EVOLUTION_API_URL'),
        'key'      => env('EVOLUTION_API_KEY'),
        'instance' => env('EVOLUTION_INSTANCE'),
        'timeout'  => (int) env('EVOLUTION_TIMEOUT', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Captain live order feed (Firebase Realtime Database)
    |--------------------------------------------------------------------------
    |
    | Orders sent to captains are mirrored under `{path}/{order_id}` through the
    | kreait/laravel-firebase project (FIREBASE_CREDENTIALS / FIREBASE_DATABASE_URL).
    |
    */

    'captain_feed' => [
        'enabled' => (bool) env('CAPTAIN_FEED_ENABLED', true),
        'path'    => env('CAPTAIN_FEED_PATH', 'available_orders'),
    ],

];
