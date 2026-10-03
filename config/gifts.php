<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Online Gifts
    |--------------------------------------------------------------------------
    |
    | Products of a special category (`categories.is_special`) can be bought
    | for someone else: POST /api/v1/gifts/checkout. Once paid, the recipient
    | gets a WhatsApp message with this link (plus `?gift=<claim code>`) and
    | the store is notified to prepare the gift.
    |
    */

    // Universal / deep link that opens the app, or the store listing when not installed
    'app_link' => env('GIFT_APP_LINK', rtrim((string) env('APP_URL', 'http://localhost'), '/').'/gift'),

    // Most units of one product per gift purchase
    'max_quantity' => (int) env('GIFT_MAX_QUANTITY', 10),

    // Days a paid gift can be redeemed at the store (a product's
    // `gift_validity_days` overrides it)
    'validity_days' => (int) env('GIFT_VALIDITY_DAYS', 90),

    // Longest personal message accepted
    'message_max_length' => (int) env('GIFT_MESSAGE_MAX_LENGTH', 500),

];
