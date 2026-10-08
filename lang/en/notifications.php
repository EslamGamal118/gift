<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Push / in-app notification texts
    |--------------------------------------------------------------------------
    |
    | Placeholders: :order_number, :store_name, :reason, :eta_max,
    | :customer_name, :total, :currency
    |
    */

    // Customer - order status changes
    'order_accepted_title'         => 'Order :order_number accepted',
    'order_accepted_body'          => ':store_name has accepted your order and will start preparing it shortly.',

    'order_processing_title'       => 'Your order is being prepared',
    'order_processing_body'        => ':store_name is now preparing order :order_number.',

    'order_ready_title'            => 'Your order is ready',
    'order_ready_body'             => 'Order :order_number is packed and waiting for a delivery captain.',

    'order_out_for_delivery_title' => 'Your order is on the way',
    'order_out_for_delivery_body'  => 'Order :order_number has been handed to the delivery captain and is on its way to you.',

    'order_delivered_title'        => 'Order delivered',
    'order_delivered_body'         => 'Order :order_number has been delivered. Enjoy your gift!',

    'order_cancelled_title'        => 'Order :order_number cancelled',
    'order_cancelled_body'         => 'Your order was cancelled by :store_name. Reason: :reason',

    // Store orders delivered by the delivery company (Alshrouq)
    'order_status_changed_title'   => 'Order :order_number updated',
    'order_status_changed_body'    => 'Your order :order_number is now: :status.',
    'order_driver_accepted_title'  => 'A driver is on your order',
    'order_driver_accepted_body'   => 'A driver accepted order :order_number and is heading to :store_name to pick it up.',
    'order_picked_up_body'         => 'The driver picked up order :order_number from :store_name and is on the way to you.',
    'order_arrived_title'          => 'Your driver has arrived',
    'order_arrived_body'           => 'The driver arrived with your order :order_number.',
    'order_cancellation_processing_title' => 'Cancellation in progress',
    'order_cancellation_processing_body'  => 'The delivery of order :order_number is being cancelled.',
    'order_delivery_cancelled_title' => 'Delivery cancelled',
    'order_delivery_cancelled_body'  => 'The delivery of order :order_number was cancelled. Our support team will contact you.',

    // Store - new paid order
    'new_order_title'              => 'New order :order_number',
    'new_order_body'               => ':customer_name placed a new order for :total :currency. Please accept it.',

    // Captains - dispatched order open to every active captain
    'delivery_request_title'       => 'New delivery request :order_number',
    'delivery_request_body'        => 'An order from :store_name to :district is ready for pickup. Take it before another captain does.',

    // Personal shopper - custom orders
    'custom_order_assigned_title'  => 'New custom order :order_number',
    'custom_order_assigned_body'   => ':customer_name chose you for a custom order with :items_count item(s). Please accept it.',
    'custom_order_bidding_title'   => 'Custom order open for offers',
    'custom_order_bidding_body'    => 'A new custom order :order_number with :items_count item(s) is waiting for your offer.',
    'custom_order_accepted_title'    => 'Your order was accepted',
    'custom_order_accepted_body'     => ':shopper_name accepted your order :order_number.',
    'custom_order_in_progress_title' => 'Shopping started',
    'custom_order_in_progress_body'  => ':shopper_name started shopping for your order :order_number.',
    'custom_order_waiting_for_payment_title' => 'Your order was purchased',
    'custom_order_waiting_for_payment_body'  => ':shopper_name purchased your order :order_number. Review the invoice and pay to complete it.',
    'custom_order_completed_title'   => 'Your order was delivered',
    'custom_order_completed_body'    => 'Your order :order_number was delivered. Enjoy!',
    'custom_order_driver_accepted_title' => 'A driver is on your order',
    'custom_order_driver_accepted_body'  => 'A driver accepted your order :order_number and is heading to pick it up.',
    'custom_order_picked_up_title'   => 'Your order is on its way',
    'custom_order_picked_up_body'    => 'The driver picked up your order :order_number.',
    'custom_order_arrived_title'     => 'Your driver has arrived',
    'custom_order_arrived_body'      => 'The driver arrived with your order :order_number.',
    'custom_order_cancellation_processing_title' => 'Cancellation in progress',
    'custom_order_cancellation_processing_body'  => 'The delivery of your order :order_number is being cancelled.',
    'custom_order_status_changed_title' => 'Order :order_number updated',
    'custom_order_status_changed_body'  => 'Your order :order_number is now: :status.',
    'custom_order_shopper_status_title' => 'Order :order_number: :status',
    'custom_order_shopper_status_body'  => 'The order :order_number of :customer_name is now: :status.',
    'custom_order_delivery_cancelled_title' => 'Delivery cancelled',
    'custom_order_delivery_cancelled_body'  => 'The delivery of your order :order_number was cancelled. Our support team will contact you.',
    'custom_order_declined_title'    => 'Your shopper declined the order',
    'custom_order_declined_body'     => ':shopper_name declined your order :order_number. Reason: :reason',
    'custom_order_alternative_title' => 'An alternative was suggested',
    'custom_order_alternative_body'  => ':shopper_name suggested an alternative to ":item_name": :product_name for :price. Review it to accept or reject.',
    'custom_order_paid_title'        => 'Payment received for :order_number',
    'custom_order_paid_body'         => ':customer_name paid :amount for order :order_number.',
    'custom_order_alternatives_answered_title' => 'Alternatives reviewed for :order_number',
    'custom_order_alternatives_answered_body' => ':customer_name approved :approved and rejected :rejected of your suggested alternatives for order :order_number.',

    // Online gifts
    'gift_purchased_title'            => 'New gift :order_number',
    'gift_purchased_body'             => 'A new gift :gift was bought for :recipient_name, mobile :recipient_phone, with the message: :message',
    'gift_purchased_body_no_message'  => 'A new gift :gift was bought for :recipient_name, mobile :recipient_phone',
    'gift_received_title'             => 'You received a gift 🎁',
    'gift_received_body'              => ':sender sent you a gift: :gift',

    /*
    |--------------------------------------------------------------------------
    | Notifications screen
    |--------------------------------------------------------------------------
    */

    'inbox' => [
        'filters' => [
            'all'      => 'All',
            'orders'   => 'Orders',
            'payments' => 'Payments',
            'system'   => 'System',
        ],
        'groups' => [
            'today'     => 'Today',
            'yesterday' => 'Yesterday',
            'older'     => 'Earlier',
        ],
        'all_marked_read' => 'All notifications were marked as read.',
    ],

];
