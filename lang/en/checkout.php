<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cart / Checkout / Payment Language Lines
    |--------------------------------------------------------------------------
    */

    // Addresses
    'address_created'  => 'Address added successfully.',
    'address_updated'  => 'Address updated successfully.',
    'address_deleted'  => 'Address deleted successfully.',
    'address_selected' => 'Delivery address selected.',

    // Cart
    'item_added'      => 'Item added to your cart.',
    'item_updated'    => 'Cart item updated.',
    'item_removed'    => 'Item removed from your cart.',
    'cart_cleared'    => 'Your cart has been emptied.',
    'invalid_addons'  => 'The selected add-ons are not available for this product: :ids.',
    'item_not_found'  => 'This item is not in your cart.',
    'cart_empty'      => 'Your cart is empty.',

    // Availability
    'store_unavailable'   => 'This store is currently unavailable for orders.',
    'product_unavailable' => ':product is no longer available.',
    'insufficient_stock'  => 'Only :available of :product left in stock.',

    // Delivery
    'today'               => 'Today',
    'tomorrow'            => 'Tomorrow',
    'delivery_saved'      => 'Delivery time saved.',
    'address_required'    => 'Please select a delivery address.',
    'delivery_required'   => 'Please select a delivery time.',
    'instant_unavailable' => 'Instant delivery is not available right now.',
    'slot_unavailable'    => 'The selected delivery slot is no longer available. Please choose another one.',

    // Gift message & promo
    'gift_message_saved' => 'Gift message saved.',
    'promo_applied'      => 'Promo code applied.',
    'promo_removed'      => 'Promo code removed.',
    'promo'              => [
        'not_found'   => 'This promo code is invalid.',
        'inactive'    => 'This promo code is not active.',
        'expired'     => 'This promo code has expired.',
        'wrong_store' => 'This promo code cannot be used with this store.',
        'min_order'   => 'This promo code requires a minimum order of :amount SAR.',
        'usage_limit' => 'This promo code has reached its usage limit.',
        'user_limit'  => 'You have already used this promo code.',
    ],

    // Orders
    'order_placed'          => 'Your order has been placed. Please complete the payment.',
    'order_cancelled'       => 'Your order has been cancelled.',
    'order_not_payable'     => 'This order cannot be paid (already paid or cancelled).',
    'order_not_cancellable' => 'This order can no longer be cancelled.',

    // Payment
    'payment_initiated'   => 'Redirect the customer to the payment page.',
    'payment_success'     => 'Payment completed successfully.',
    'payment_cancel'      => 'Payment was cancelled.',
    'payment_failure'     => 'Payment failed. Please try again or use another method.',
    'gateway_unavailable' => ':gateway is not available at the moment.',
    'gateway_error'       => 'Payment gateway error: :detail',

    // Messages Tabby requires merchants to show when it declines a purchase
    'tabby' => [
        'rejection' => [
            'not_available'         => 'Sorry, Tabby is unable to approve this purchase. Please use an alternative payment method for your order.',
            'order_amount_too_high' => 'This purchase is above your current spending limit with Tabby, try a smaller cart or use another payment method.',
            'order_amount_too_low'  => 'The purchase amount is below the minimum amount required to use Tabby, try adding more items or use another payment method.',
        ],
    ],

];
