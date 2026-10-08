<?php

use App\Models\CustomOrder;

return [

    /*
    |--------------------------------------------------------------------------
    | Alshrouq Delivery
    |--------------------------------------------------------------------------
    |
    | Delivers paid custom orders. When the shopper sends a paid order to the
    | driver (status `send_to_driver`), it is created at Alshrouq, picked up at the shopper's
    | pickup address (no Alshrouq branch) and dropped at the customer's
    | delivery address. Alshrouq then posts every status change to
    | POST /api/v1/webhooks/alshrouq (CustomOrderDeliveryService).
    |
    */

    // Store orders: `send_to_captain` creates the order at Alshrouq instead of
    // broadcasting it to the app's captains (needs the token below)
    'store_orders' => (bool) env('ALSHROUQ_STORE_ORDERS', true),

    // Integration token: part of every API URL, never logged. Empty = orders are not dispatched.
    'api_token' => env('ALSHROUQ_API_TOKEN'),

    // Staging: https://staging.alshrouqsse.org/api/integration
    'base_url' => env('ALSHROUQ_BASE_URL', 'https://staging.alshrouqsse.org/api/integration'),

    'timeout' => (int) env('ALSHROUQ_TIMEOUT', 20),

    // 1 = COD, 2 = SPAN machine, 3 = Paid, 4 = AlshrouqPay. Custom orders are paid in the app.
    'payment_type' => (int) env('ALSHROUQ_PAYMENT_TYPE', 3),

    // Minutes before the driver can pick up (the shopper already holds the items)
    'preparation_time' => (int) env('ALSHROUQ_PREPARATION_TIME', 0),

    // Shared secret for the webhook, sent by Alshrouq as `?token=` in the webhook URL
    // or in `webhook_header`. The webhook refuses every call while it is empty.
    'webhook_secret' => env('ALSHROUQ_WEBHOOK_SECRET'),

    'webhook_header' => env('ALSHROUQ_WEBHOOK_HEADER', 'X-Alshrouq-Secret'),

    // Alshrouq status id => our status. Status names (matched case-insensitively)
    // are a fallback for payloads without an id.
    'statuses' => [
        1 => CustomOrder::STATUS_ORDER_CREATED,
        2 => CustomOrder::STATUS_PENDING_DRIVER_ACCEPTANCE,
        17 => CustomOrder::STATUS_DRIVER_ACCEPTED,
        4 => CustomOrder::STATUS_PENDING_ORDER_PREPARATION,
        16 => CustomOrder::STATUS_ARRIVED_TO_PICKUP,
        6 => CustomOrder::STATUS_ORDER_PICKED_UP,
        8 => CustomOrder::STATUS_ARRIVED_TO_DROPOFF,
        9 => CustomOrder::STATUS_COMPLETED,
        10 => CustomOrder::STATUS_CANCELLED,
        21 => CustomOrder::STATUS_CANCELLATION_PROCESSING,

        'Order created' => CustomOrder::STATUS_ORDER_CREATED,
        'Pending driver acceptance' => CustomOrder::STATUS_PENDING_DRIVER_ACCEPTANCE,
        'Driver accepted the order' => CustomOrder::STATUS_DRIVER_ACCEPTED,
        'Pending order preparation' => CustomOrder::STATUS_PENDING_ORDER_PREPARATION,
        'Arrived to pickup' => CustomOrder::STATUS_ARRIVED_TO_PICKUP,
        'Order picked up' => CustomOrder::STATUS_ORDER_PICKED_UP,
        'Arrived to dropoff' => CustomOrder::STATUS_ARRIVED_TO_DROPOFF,
        'Order delivered' => CustomOrder::STATUS_COMPLETED,
        'Order cancelled' => CustomOrder::STATUS_CANCELLED,
        'Order Cancellation is being Processed' => CustomOrder::STATUS_CANCELLATION_PROCESSING,
    ],

];
