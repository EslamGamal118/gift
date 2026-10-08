<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Currency & Tax
    |--------------------------------------------------------------------------
    |
    | Tax is applied to the taxable base (subtotal - discount + delivery). When
    | `prices_include_tax` is true the tax is extracted from the total instead
    | of being added on top of it.
    |
    */

    'currency' => env('CHECKOUT_CURRENCY', 'SAR'),

    'tax' => [
        'rate' => (float) env('CHECKOUT_TAX_RATE', 0.15),
        'prices_include_tax' => (bool) env('CHECKOUT_PRICES_INCLUDE_TAX', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Free Delivery
    |--------------------------------------------------------------------------
    |
    | Delivery is on us (our delivery partner, Alshrouq): the customer is never
    | charged for it. Every delivery fee (distance pricing or a store's flat
    | fee), the instant-delivery fee and the custom order delivery fee are 0,
    | in the cart, at checkout, on the orders and wherever a fee is shown.
    | Delivery types, slots and ETAs are unchanged. Off: the pricing in
    | config/stores.php `delivery` and custom_orders.delivery.fee applies.
    |
    */

    'free_delivery' => (bool) env('CHECKOUT_FREE_DELIVERY', true),

    /*
    |--------------------------------------------------------------------------
    | Cart
    |--------------------------------------------------------------------------
    */

    'cart' => [
        'max_quantity_per_item' => (int) env('CART_MAX_QUANTITY_PER_ITEM', 50),
        'gift_message_max' => 500,
        // Products suggested under the cart (GET /cart/suggested-products)
        'suggestions_limit' => (int) env('CART_SUGGESTIONS_LIMIT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Delivery Scheduling
    |--------------------------------------------------------------------------
    |
    | `days_ahead` is how many days (including today) a customer may schedule
    | for. A slot on the current day is offered only when it starts at least
    | `slot_lead_minutes` from now. Instant delivery adds a flat fee and a
    | fixed ETA window; it is offered only while the store is open.
    |
    */

    'delivery' => [
        'timezone' => env('STORE_TIMEZONE', 'Asia/Riyadh'),
        'days_ahead' => (int) env('DELIVERY_DAYS_AHEAD', 7),
        'slot_lead_minutes' => (int) env('DELIVERY_SLOT_LEAD_MINUTES', 60),

        'instant' => [
            'enabled' => (bool) env('DELIVERY_INSTANT_ENABLED', true),
            'fee' => (float) env('DELIVERY_INSTANT_FEE', 10),
            'eta_min' => (int) env('DELIVERY_INSTANT_ETA_MIN', 30),
            'eta_max' => (int) env('DELIVERY_INSTANT_ETA_MAX', 45),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Orders
    |--------------------------------------------------------------------------
    */

    'orders' => [
        'number_prefix' => env('ORDER_NUMBER_PREFIX', 'GFT'),
        // Gateways the customer may pick on the checkout screen.
        'payment_methods' => ['alrajhi', 'tamara', 'tabby'],
    ],

    // Support contacts: the "Contact us" screen cards and "Contact support" on
    // order details (null = not shown). `inbox` receives "Contact us" messages
    // (defaults to the public support email).
    'support' => [
        'phone' => env('SUPPORT_PHONE'),
        'whatsapp' => env('SUPPORT_WHATSAPP'),
        'email' => env('SUPPORT_EMAIL'),
        'inbox' => env('SUPPORT_INBOX'),
    ],

];
