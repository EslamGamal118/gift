<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Custom Orders (Personal Shopper)
    |--------------------------------------------------------------------------
    */

    'number_prefix' => env('CUSTOM_ORDER_NUMBER_PREFIX', 'CO'),
    'currency'      => env('CUSTOM_ORDER_CURRENCY', env('STORE_CURRENCY', 'SAR')),

    // Request limits (validated by StoreCustomOrderRequest)
    'items' => [
        'max_items'       => (int) env('CUSTOM_ORDER_MAX_ITEMS', 20),
        'max_quantity'    => (int) env('CUSTOM_ORDER_MAX_QUANTITY', 1000),
        'max_price'       => 999999.99,
        'max_images'      => (int) env('CUSTOM_ORDER_MAX_IMAGES_PER_ITEM', 5),
        'max_image_kb'    => (int) env('CUSTOM_ORDER_MAX_IMAGE_KB', 4096),
        'image_mimes'     => ['jpg', 'jpeg', 'png', 'webp', 'heic'],
        // Purchase invoice uploaded by the shopper (SubmitInvoiceRequest)
        'invoice_mimes'   => ['jpeg', 'png', 'jpg'],
        'max_invoice_kb'  => (int) env('CUSTOM_ORDER_MAX_INVOICE_KB', 2048),
        // Where reference images are stored on the public disk
        'media_directory' => 'custom-orders',
    ],

    'delivery' => [
        // Earliest delivery a customer may request, from now
        'min_lead_minutes' => (int) env('CUSTOM_ORDER_MIN_LEAD_MINUTES', 60),
        // Latest delivery a customer may request, from now
        'max_lead_days'    => (int) env('CUSTOM_ORDER_MAX_LEAD_DAYS', 30),
        // Flat delivery fee added to the total when the shopper submits the invoice
        'fee'              => (float) env('CUSTOM_ORDER_DELIVERY_FEE', 0),
    ],

    'shoppers' => [
        // Default radius when the customer sends a position but no `within_km`
        'nearby_radius_km' => (float) env('CUSTOM_ORDER_SHOPPER_RADIUS_KM', 50),
        'per_page'         => (int) env('CUSTOM_ORDER_SHOPPERS_PER_PAGE', 15),
    ],

];
