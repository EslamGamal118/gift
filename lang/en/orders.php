<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Store Order Management Language Lines
    |--------------------------------------------------------------------------
    */

    // Action results
    'accepted'   => 'Order accepted.',
    'processing' => 'Order is now being prepared.',
    'ready'      => 'Order marked as ready.',
    'dispatched' => 'Order sent to the available captains for pickup.',
    'dispatched_to_delivery' => 'Order sent to the delivery company; a driver will be assigned to pick it up.',
    'delivery_failed' => 'The order could not be sent to the delivery company. Please try again.',
    'delivery_store_location_required' => 'Set the store main branch location on the map before sending the order to the driver.',
    'delivery_customer_location_required' => 'The customer address has no map location, so it cannot be sent to the delivery company.',
    'cancelled'  => 'Order cancelled.',
    'delivered'  => 'Order marked as delivered.',

    // Errors
    'not_found'           => 'Order not found.',
    'invalid_transition'  => 'You cannot ":action" an order that is currently ":status".',
    'unknown_action'      => 'Unknown order action ":action".',
    'status_not_allowed'  => 'An order that is ":status" can only move to: :allowed.',
    'no_next_status'      => 'no further status',

    // Labels
    'statuses' => [
        'pending_payment'  => 'Awaiting payment',
        'pending'          => 'New',
        'accepted'         => 'Accepted',
        'processing'       => 'Preparing',
        'ready'            => 'Ready',
        'out_for_delivery' => 'Out for delivery',
        'delivered'        => 'Delivered',
        'cancelled'        => 'Cancelled',
        'order_created' => 'Sent to the delivery company',
        'pending_driver_acceptance' => 'Waiting for a driver',
        'driver_accepted' => 'Driver assigned',
        'pending_order_preparation' => 'Waiting for the store',
        'arrived_to_pickup' => 'Driver at the store',
        'order_picked_up' => 'On the way',
        'arrived_to_dropoff' => 'Arrived',
        'cancellation_processing' => 'Cancelling delivery',
    ],

    'actions' => [
        'accept'          => 'accept',
        'start_preparing' => 'start preparing',
        'ready'           => 'mark as ready',
        'dispatch'        => 'send to captain',
        'deliver'         => 'mark as delivered',
        'cancel'          => 'cancel',
    ],

    // Order card on "My orders" (customer app)
    'customer_actions' => [
        'view_details' => 'Order details',
        'track'   => 'Track order',
        'details' => 'Order details',
        'pay'     => 'Complete payment',
        'cancel'  => 'Cancel order',
        'contact_support' => 'Contact support',
        'visit_store' => 'Visit store',
    ],

    // Payment summary (order details screen)
    'summary' => [
        'subtotal'     => 'Subtotal',
        'delivery_fee' => 'Delivery fee',
        'express_fee'  => 'Instant delivery fee',
        'discount'     => 'Discount',
        'tax'          => 'VAT (:rate%)',
        'total'        => 'Total',
        'free'         => 'Free',
    ],

    // "My orders" tabs and card location
    'tabs' => [
        'active'  => 'Active',
        'history' => 'Previous',
    ],
    'location' => [
        'full'     => ':city, :district',
        'district' => ':district',
    ],
    'today'     => 'Today',
    'yesterday' => 'Yesterday',
    'placed_at' => ':day, :time',
    'tomorrow'  => 'Tomorrow',
    'time_range' => ':day, :from - :to',

    // Status badge on the order card (others fall back to customer_statuses)
    'badges' => [
        'delivered' => 'Completed',
    ],

    // Order status as the customer sees it (order details screen)
    'customer_statuses' => [
        'pending_payment'  => ['label' => 'Awaiting payment', 'description' => 'Complete the payment to send your order to the store.'],
        'pending'          => ['label' => 'Under review', 'description' => 'Your order will be confirmed shortly.'],
        'accepted'         => ['label' => 'Order confirmed', 'description' => 'The store accepted your order and will start preparing it.'],
        'processing'       => ['label' => 'Preparing', 'description' => 'The store is preparing your order.'],
        'ready'            => ['label' => 'Ready for pickup', 'description' => 'Your order is ready and waiting for the courier.'],
        'out_for_delivery' => ['label' => 'On the way', 'description' => 'Your order is on its way to you.'],
        'delivered'        => ['label' => 'Delivered', 'description' => 'Your order was delivered. Enjoy!'],
        'cancelled'        => ['label' => 'Cancelled', 'description' => 'This order was cancelled.'],
        'order_created' => ['label' => 'Sent to the delivery company', 'description' => 'Your order was sent to the delivery company; a driver will be assigned shortly.'],
        'pending_driver_acceptance' => ['label' => 'Waiting for a driver', 'description' => 'We are finding the nearest driver to pick up your order.'],
        'driver_accepted' => ['label' => 'Driver assigned', 'description' => 'A driver accepted your order and is heading to the store.'],
        'pending_order_preparation' => ['label' => 'Waiting for the store', 'description' => 'The driver is waiting to receive your order from the store.'],
        'arrived_to_pickup' => ['label' => 'Driver at the store', 'description' => 'The driver arrived at the store to pick up your order.'],
        'order_picked_up' => ['label' => 'On the way', 'description' => 'The driver picked up your order and is on the way to you.'],
        'arrived_to_dropoff' => ['label' => 'Arrived', 'description' => 'The driver arrived at your location.'],
        'cancellation_processing' => ['label' => 'Cancelling delivery', 'description' => 'The delivery of your order is being cancelled.'],
    ],

    // Store app: order badge, items count and details screen title
    'store_badges' => [
        'new'              => 'New order',
        'accepted'         => 'Accepted',
        'preparing'        => 'Preparing',
        'ready_for_pickup' => 'Ready for pickup',
        'out_for_delivery' => 'On the way',
        'completed'        => 'Completed',
        'cancelled'        => 'Cancelled',
    ],
    'items_count_label' => '{0} No items|{1} 1 item ordered|[2,*] :count items ordered',
    'store_order_header' => 'Order :reference - :date',

];
