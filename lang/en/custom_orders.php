<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Custom Orders (Personal Shopper) Language Lines
    |--------------------------------------------------------------------------
    */

    // Action results
    'created'           => 'Your custom order has been created.',
    'submitted'         => 'Your custom order has been sent to the shopper.',
    'assigned'          => 'The shopper has been chosen for your order.',
    'bidding_opened'    => 'Your order is set to receive shopper offers.',
    'confirmed'         => 'Your custom order has been confirmed and sent to the shopper.',
    'confirmed_bidding' => 'Your custom order has been confirmed and is now open for shopper offers.',
    'cancelled'         => 'Custom order cancelled.',

    // Errors
    'not_found'           => 'Custom order not found.',
    'invalid_transition'  => 'A custom order that is ":from" cannot move to ":to".',
    'not_assignable'      => 'A shopper cannot be chosen for an order that is ":status".',
    'not_cancellable'     => 'An order that is ":status" cannot be cancelled.',
    'alternatives_closed' => 'Alternatives cannot be suggested for an order that is ":status".',
    'status_updated'      => 'Order status updated.',
    'alternative_sent'    => 'The suggested alternative was sent to the customer.',
    'alternatives_answered' => 'Your answers were sent to the shopper.',
    'alternative_not_pending' => 'This alternative is not waiting for your answer.',
    'alternatives_not_open' => 'Alternatives cannot be answered for an order that is ":status".',
    'invoice_submitted'   => 'Invoice and prices submitted.',
    'invoice_closed'      => 'The invoice cannot be submitted for an order that is ":status".',
    'already_paid'        => 'The customer has already paid this order; its invoice and prices can no longer change.',
    'not_payable_yet'     => 'This order can be paid once your shopper has submitted the invoice.',
    'shopper_fees_line'   => 'Personal shopper fees',
    'item_not_in_order'   => 'This item is not part of the order.',
    'items_unpriced'      => 'Every item needs the price paid (0 for an item that was not bought).',
    'not_confirmable'     => 'An order that is ":status" cannot be confirmed.',
    'shopper_not_chosen'  => 'Please choose a shopper or open the order for offers before confirming.',
    'shopper_unavailable' => 'The selected shopper is not available right now.',
    'address_required'    => 'Please choose a saved address or enter a delivery address.',
    'address_not_found'   => 'The selected address was not found.',
    'pickup_address_required' => 'Choose a saved pickup address or enter a new one.',
    'delivery_required'   => 'Please choose a delivery time or a delivery slot.',
    'slot_unavailable'    => 'The selected delivery slot is not available.',

    // Validation
    'price_range_invalid'  => 'The maximum expected price must be greater than or equal to the minimum.',
    'budget_range_invalid' => 'The maximum budget must be greater than or equal to the minimum.',
    'delivery_too_soon'    => 'The delivery time must be at least :minutes minutes from now.',
    'delivery_too_far'     => 'The delivery time must be within the next :days days.',

    // Labels
    'statuses' => [
        'draft'       => 'Draft',
        'pending'     => 'Waiting for shopper',
        'accepted'    => 'Accepted',
        'in_progress' => 'Shopping in progress',
        'waiting_for_alternative' => 'Waiting for alternative approval',
        'waiting_for_payment' => 'Waiting for payment',
        'completed'   => 'Completed',
        'cancelled'   => 'Cancelled',
    ],

    'assignment_modes' => [
        'direct'  => 'Chosen shopper',
        'bidding' => 'Open for offers',
    ],

    'bid_statuses' => [
        'pending'   => 'Pending',
        'accepted'  => 'Accepted',
        'rejected'  => 'Rejected',
        'withdrawn' => 'Withdrawn',
    ],

    // Status badges in the shopper app (GET /shopper/orders)
    'shopper_badges' => [
        'new'         => 'New',
        'accepted'    => 'Accepted',
        'in_progress' => 'In progress',
        'waiting_for_alternative' => 'Waiting for alternative',
        'waiting_for_payment' => 'Purchased - waiting for payment',
        'completed'   => 'Completed',
        'cancelled'   => 'Cancelled',
    ],

    // Shopper home screen (GET /shopper/home)
    'home' => [
        'vs_last_week'  => ':change vs last week',
        'cart_size'     => '{0} No items requested|{1} :count item requested|[2,*] :count items requested',
    ],

    // Shopper statistics screen (GET /shopper/statistics)
    'statistics' => [
        'minutes' => '{1} :count minute|[2,*] :count minutes',
    ],

    'shopper' => [
        'available'   => 'Available',
        'unavailable' => 'Busy',
        'completed_orders' => '{0} No completed orders|{1} :count completed order|[2,*] :count completed orders',
    ],

];
