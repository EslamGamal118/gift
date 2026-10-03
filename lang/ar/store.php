<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Store Catalog Language Lines (products & add-ons)
    |--------------------------------------------------------------------------
    */

    // Products
    'product_created' => 'تمت إضافة المنتج بنجاح.',
    'product_updated' => 'تم تحديث المنتج بنجاح.',
    'product_deleted' => 'تم حذف المنتج بنجاح.',

    // Add-ons
    'addon_created'            => 'تمت إضافة الإضافة بنجاح.',
    'addon_updated'            => 'تم تحديث الإضافة بنجاح.',
    'addon_deleted'            => 'تم حذف الإضافة بنجاح.',
    'addon_categories_updated' => 'تم تحديث تصنيفات الإضافة بنجاح.',

    // Field errors
    'expiry_date_in_past' => 'يجب أن يكون تاريخ الانتهاء اليوم أو تاريخًا مستقبليًا.',

    // Customer store screen
    'tabs' => [
        'best_sellers' => 'الأكثر مبيعًا',
        'all'          => 'كل المنتجات',
    ],
    'reviews' => [
        'anonymous' => 'عميل',
        'count'     => '{0} لا توجد تقييمات بعد|{1} تقييم واحد|{2} تقييمان|[3,10] :count تقييمات|[11,*] :count تقييمًا',
    ],

    // شاشة تفاصيل المنتج
    'product' => [
        'visit_store'   => 'زيارة المتجر',
        'related_title' => 'قد يعجبك أيضًا',
        'addons_title'  => 'أضف إلى هديتك',
    ],

    // عرض السعر: ":amount :currency" -> "49.00 ر.س"
    'price_format' => ':amount :currency',
    'currency'     => [
        'SAR' => 'ر.س',
    ],
    'days' => [
        'saturday'  => 'السبت',
        'sunday'    => 'الأحد',
        'monday'    => 'الاثنين',
        'tuesday'   => 'الثلاثاء',
        'wednesday' => 'الأربعاء',
        'thursday'  => 'الخميس',
        'friday'    => 'الجمعة',
    ],

];
