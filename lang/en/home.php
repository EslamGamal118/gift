<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Home Screen Language Lines
    |--------------------------------------------------------------------------
    */

    'greeting_user'           => 'Hello, :name',
    'greeting_user_anonymous' => 'Hello',
    'greeting_guest'          => 'Welcome, Guest',

    // Location selector
    'location_current'  => 'Current location',
    'location_prompt'   => 'Enable location services to see stores and delivery times near you.',
    'location_required' => 'Send your coordinates or choose a city to find nearby stores.',

    // Distance labels beside the pin icon
    'distance_km' => ':km km',
    'distance_m'  => ':m m',

    // Online gifts
    'slots_available' => '{0} Sold out|{1} 1 slot available|[2,*] :count slots available',

    // "You received a new gift" popup
    'incoming_gift' => [
        'title'          => 'You received a new gift',
        'subtitle'       => 'A gift from :name is waiting for you. Open it now!',
        'subtitle_any'   => 'A gift is waiting for you. Open it now!',
        'open_button'    => 'Open my gift',
        'dismiss_button' => 'Not now',
    ],

    // "Send your order" (personal shopper) banner
    'custom_order' => [
        'title'       => 'Order it, and let it be done for you',
        'description' => 'A personal shopper fulfils your exact request, and proposes suitable alternatives if a product is missing.',
        'button'      => 'Send your order',
    ],

];
