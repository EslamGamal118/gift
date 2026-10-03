<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Store Timezone
    |--------------------------------------------------------------------------
    |
    | Working hours are entered by merchants in local time. This timezone is
    | used to decide whether a store is currently open.
    |
    */

    'timezone' => env('STORE_TIMEZONE', 'Asia/Riyadh'),

    /*
    |--------------------------------------------------------------------------
    | Delivery Estimation
    |--------------------------------------------------------------------------
    |
    | Used by App\Services\DeliveryCalculatorService to price delivery and
    | estimate its time from the distance between the customer and the store's
    | nearest branch. A store may override the fee with a flat `delivery_fee`
    | on its profile.
    |
    | Distances are straight-line (ST_Distance_Sphere) multiplied by
    | `road_factor` to approximate the real driving distance. Every distance
    | shown to customers, and every radius filter, uses this road distance.
    |
    */

    'delivery' => [
        'currency'                    => env('STORE_CURRENCY', 'SAR'),
        'road_factor'                 => (float) env('DELIVERY_ROAD_FACTOR', 1.25),

        // Fee = base_fee for the first base_distance_km, then fee_per_extra_km
        // for each started km beyond it, capped at max_fee.
        'pricing' => [
            'base_fee'         => (float) env('DELIVERY_BASE_FEE', 10),
            'base_distance_km' => (float) env('DELIVERY_BASE_DISTANCE_KM', 3),
            'fee_per_extra_km' => (float) env('DELIVERY_FEE_PER_KM', 1.5),
            'max_fee'          => (float) env('DELIVERY_MAX_FEE', 60),
        ],

        'max_distance_km'             => (float) env('DELIVERY_MAX_DISTANCE_KM', 50),
        'average_speed_kmh'           => (float) env('DELIVERY_AVERAGE_SPEED_KMH', 30),
        'default_preparation_minutes' => (int) env('DELIVERY_DEFAULT_PREPARATION_MINUTES', 20),
        'buffer_minutes'              => (int) env('DELIVERY_BUFFER_MINUTES', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Home Screen
    |--------------------------------------------------------------------------
    |
    | Section sizes and the default "nearby" radius used by GET /home and
    | GET /stores/nearby when the client does not send `within_km`.
    |
    */

    'home' => [
        'nearby_radius_km'  => (float) env('HOME_NEARBY_RADIUS_KM', 50),
        'nearby_limit'      => (int) env('HOME_NEARBY_LIMIT', 10),
        'featured_products' => (int) env('HOME_FEATURED_PRODUCTS', 10),
        'online_gifts'      => (int) env('HOME_ONLINE_GIFTS', 5),
        // Seconds to cache the static sections (banners, categories) per locale
        'cache_ttl'         => (int) env('HOME_CACHE_TTL', 300),
        // Minutes a signed-in customer's last GPS position is reused when a
        // later request comes without coordinates
        'location_ttl'      => (int) env('HOME_LOCATION_TTL', 1440),
    ],

    /*
    |--------------------------------------------------------------------------
    | Store Screen
    |--------------------------------------------------------------------------
    */

    'show' => [
        // Products in the first page returned with GET /stores/{id}
        'products_per_page' => (int) env('STORE_SHOW_PRODUCTS', 10),
        // Latest reviews embedded in GET /stores/{id}
        'recent_reviews'    => (int) env('STORE_SHOW_REVIEWS', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Product Details Screen
    |--------------------------------------------------------------------------
    */

    'product' => [
        // Optional add-ons offered with the product (chocolates, cards, ...)
        'addons_limit'   => (int) env('PRODUCT_SHOW_ADDONS', 20),
        // "You may also like" products from the same store
        'related_limit'  => (int) env('PRODUCT_SHOW_RELATED', 8),
        // Latest store reviews embedded in GET /products/{id}
        'recent_reviews' => (int) env('PRODUCT_SHOW_REVIEWS', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Search
    |--------------------------------------------------------------------------
    */

    'search' => [
        // Items per group when searching across all types at once
        'group_limit'   => 5,
        // Recent searches kept per user
        'history_limit' => 20,
    ],

];
