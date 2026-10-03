<?php

return [

    /*
    |--------------------------------------------------------------------------
    | رسائل إدارة الطلبات (تطبيق المتجر)
    |--------------------------------------------------------------------------
    */

    // نتائج الإجراءات
    'accepted'   => 'تم قبول الطلب.',
    'processing' => 'بدأ تجهيز الطلب.',
    'ready'      => 'تم تحديد الطلب كجاهز.',
    'dispatched' => 'تم إرسال الطلب للكباتن المتاحين لاستلامه.',
    'cancelled'  => 'تم إلغاء الطلب.',
    'delivered'  => 'تم تأكيد توصيل الطلب.',

    // الأخطاء
    'not_found'           => 'الطلب غير موجود.',
    'invalid_transition'  => 'لا يمكنك ":action" لطلب حالته الحالية ":status".',
    'unknown_action'      => 'إجراء غير معروف ":action".',
    'status_not_allowed'  => 'الطلب حالته ":status" ويمكن نقله فقط إلى: :allowed.',
    'no_next_status'      => 'لا توجد حالة تالية',

    // التسميات
    'statuses' => [
        'pending_payment'  => 'بانتظار الدفع',
        'pending'          => 'جديد',
        'accepted'         => 'مقبول',
        'processing'       => 'قيد التجهيز',
        'ready'            => 'جاهز',
        'out_for_delivery' => 'في الطريق',
        'delivered'        => 'تم التوصيل',
        'cancelled'        => 'ملغى',
    ],

    'actions' => [
        'accept'          => 'قبول',
        'start_preparing' => 'بدء التجهيز',
        'ready'           => 'تحديد كجاهز',
        'dispatch'        => 'إرسال للكابتن',
        'deliver'         => 'تحديد كمُسلَّم',
        'cancel'          => 'إلغاء',
    ],

    // بطاقة الطلب في "طلباتي" (تطبيق العميل)
    'customer_actions' => [
        'view_details' => 'تفاصيل طلب',
        'track'   => 'تتبع الطلب',
        'details' => 'تفاصيل الطلب',
    ],
    'today'     => 'اليوم',
    'yesterday' => 'أمس',
    'placed_at' => ':day، :time',
    'tomorrow'  => 'غدًا',
    'time_range' => ':day، :from - :to',

    // شارة الحالة على بطاقة الطلب (الباقي من customer_statuses)
    'badges' => [
        'delivered' => 'مكتمل',
    ],

    // حالة الطلب كما يراها العميل (شاشة تفاصيل الطلب)
    'customer_statuses' => [
        'pending_payment'  => ['label' => 'بانتظار الدفع', 'description' => 'أكمل الدفع لإرسال طلبك إلى المتجر.'],
        'pending'          => ['label' => 'قيد المراجعة', 'description' => 'سيتم تأكيد طلبك قريباً.'],
        'accepted'         => ['label' => 'تم تأكيد الطلب', 'description' => 'قبل المتجر طلبك وسيبدأ تجهيزه.'],
        'processing'       => ['label' => 'قيد التجهيز', 'description' => 'يقوم المتجر بتجهيز طلبك الآن.'],
        'ready'            => ['label' => 'جاهز للاستلام', 'description' => 'طلبك جاهز وبانتظار المندوب.'],
        'out_for_delivery' => ['label' => 'في الطريق', 'description' => 'طلبك في الطريق إليك.'],
        'delivered'        => ['label' => 'تم التوصيل', 'description' => 'تم توصيل طلبك. نتمنى أن ينال إعجابك!'],
        'cancelled'        => ['label' => 'ملغى', 'description' => 'تم إلغاء هذا الطلب.'],
    ],

    // تطبيق المتجر: شارة الطلب، عدد المنتجات وعنوان شاشة التفاصيل
    'store_badges' => [
        'new'              => 'طلب جديد',
        'accepted'         => 'مقبول',
        'preparing'        => 'قيد التجهيز',
        'ready_for_pickup' => 'جاهز للاستلام',
        'out_for_delivery' => 'في الطريق',
        'completed'        => 'مكتمل',
        'cancelled'        => 'ملغى',
    ],
    'items_count_label' => '{0} لا توجد منتجات|{1} منتج واحد مطلوب|{2} منتجان مطلوبان|[3,10] :count منتجات مطلوبة|[11,*] :count منتجًا مطلوبًا',
    'store_order_header' => 'طلب :reference - :date',

];
