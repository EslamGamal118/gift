<?php

/*
|--------------------------------------------------------------------------
| Serviced Cities
|--------------------------------------------------------------------------
|
| Cities a customer can pick manually when GPS is unavailable or denied.
| The centroid is used as the customer's position for distance sorting, so
| distances computed from a city are flagged as approximate in the API.
|
| `radius_km` is the default "nearby" radius for that city.
|
*/

return [

    'default' => env('DEFAULT_CITY', 'riyadh'),

    'list' => [
        'riyadh'  => ['name' => ['ar' => 'الرياض', 'en' => 'Riyadh'],          'latitude' => 24.7136, 'longitude' => 46.6753, 'radius_km' => 35],
        'jeddah'  => ['name' => ['ar' => 'جدة', 'en' => 'Jeddah'],             'latitude' => 21.4858, 'longitude' => 39.1925, 'radius_km' => 30],
        'makkah'  => ['name' => ['ar' => 'مكة المكرمة', 'en' => 'Makkah'],     'latitude' => 21.3891, 'longitude' => 39.8579, 'radius_km' => 25],
        'madinah' => ['name' => ['ar' => 'المدينة المنورة', 'en' => 'Madinah'], 'latitude' => 24.5247, 'longitude' => 39.5692, 'radius_km' => 25],
        'dammam'  => ['name' => ['ar' => 'الدمام', 'en' => 'Dammam'],          'latitude' => 26.4207, 'longitude' => 50.0888, 'radius_km' => 30],
        'khobar'  => ['name' => ['ar' => 'الخبر', 'en' => 'Khobar'],           'latitude' => 26.2172, 'longitude' => 50.1971, 'radius_km' => 25],
        'abha'    => ['name' => ['ar' => 'أبها', 'en' => 'Abha'],              'latitude' => 18.2164, 'longitude' => 42.5053, 'radius_km' => 20],
        'tabuk'   => ['name' => ['ar' => 'تبوك', 'en' => 'Tabuk'],             'latitude' => 28.3838, 'longitude' => 36.5550, 'radius_km' => 20],
        'buraidah' => ['name' => ['ar' => 'بريدة', 'en' => 'Buraidah'],        'latitude' => 26.3260, 'longitude' => 43.9750, 'radius_km' => 20],
        'hail'    => ['name' => ['ar' => 'حائل', 'en' => 'Hail'],              'latitude' => 27.5114, 'longitude' => 41.7208, 'radius_km' => 20],
    ],

];
