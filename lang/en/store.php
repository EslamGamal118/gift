<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Store Catalog Language Lines (products & add-ons)
    |--------------------------------------------------------------------------
    */

    // Products
    'product_created' => 'Product added successfully.',
    'product_updated' => 'Product updated successfully.',
    'product_deleted' => 'Product deleted successfully.',

    // Add-ons
    'addon_created'            => 'Add-on added successfully.',
    'addon_updated'            => 'Add-on updated successfully.',
    'addon_deleted'            => 'Add-on deleted successfully.',
    'addon_categories_updated' => 'Add-on categories updated successfully.',

    // Field errors
    'expiry_date_in_past' => 'The expiry date must be today or a future date.',

    // Customer store screen
    'tabs' => [
        'best_sellers' => 'Best Sellers',
        'all'          => 'All Products',
    ],
    'reviews' => [
        'anonymous' => 'Customer',
        'count'     => '{0} No reviews yet|{1} :count review|[2,*] :count reviews',
    ],

    // Product details screen
    'product' => [
        'visit_store'   => 'Visit Store',
        'related_title' => 'You may also like',
        'addons_title'  => 'Add to your gift',
    ],

    // Price display: ":amount :currency" -> "49.00 SAR"
    'price_format' => ':amount :currency',
    'currency'     => [
        'SAR' => 'SAR',
    ],
    'days' => [
        'saturday'  => 'Saturday',
        'sunday'    => 'Sunday',
        'monday'    => 'Monday',
        'tuesday'   => 'Tuesday',
        'wednesday' => 'Wednesday',
        'thursday'  => 'Thursday',
        'friday'    => 'Friday',
    ],

];
