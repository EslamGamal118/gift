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
