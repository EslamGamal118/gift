<?php

return [

    'checkout_created' => 'Gift order created, please complete the payment.',
    'not_giftable'     => 'This product cannot be sent as a gift.',
    'addon_unavailable' => 'This add-on is not available for this gift.',
    'someone'          => 'Someone who cares about you',

    // Snapshot on the order (online gifts have no delivery address)
    'order_location'   => 'Online gift',
    'order_address'    => 'Online gift for :name',

    // "Online gifts" screen (received gifts)
    'screen' => [
        'title' => 'Online gifts',
        'tabs'  => [
            'available' => 'Available',
            'finished'  => 'Finished',
        ],
    ],
    'statuses' => [
        'active'   => 'Available',
        'redeemed' => 'Redeemed',
        'expired'  => 'Expired',
    ],

    // Scanning a gift code at the store
    'redeem' => [
        'done'             => 'The gift was redeemed.',
        'not_found'        => 'This gift code is invalid or belongs to another store.',
        'already_redeemed' => 'This gift was already redeemed.',
        'expired'          => 'This gift has expired.',
    ],

    // WhatsApp message to the recipient (paragraphs joined with blank lines)
    'whatsapp' => [
        'greeting' => 'Hello :recipient 🎁',
        'body'     => ':sender sent you a gift: :gift',
        'message'  => 'Gift message: “:message”',
        'link'     => 'Download the app and claim your gift here: :link',
        'code'     => 'Gift claim code: :code',
    ],

    // SMS with the claim code to the recipient's phone
    'sms' => ':app: :sender sent you a gift 🎁 Claim code: :code (Online gifts > Claim a gift)',

    // Claiming a gift with a code
    'claim' => [
        'done'            => 'Gift claimed. You will find it in your available gifts.',
        'invalid_code'    => 'This gift code is invalid.',
        'already_claimed' => 'This gift was already claimed.',
        'own_gift'        => 'You cannot claim a gift you sent.',
        'expired'         => 'This gift has expired.',
        'code_attribute'  => 'gift code',
    ],

];
