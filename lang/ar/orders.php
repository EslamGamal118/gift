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
    'dispatched_to_delivery' => 'تم إرسال الطلب لشركة التوصيل، وسيتم تعيين مندوب لاستلامه.',
    'delivery_failed' => 'تعذر إرسال الطلب لشركة التوصيل، يرجى المحاولة مرة أخرى.',
    'delivery_store_location_required' => 'حدد موقع الفرع الرئيسي للمتجر على الخريطة قبل إرسال الطلب للمندوب.',
    'delivery_customer_location_required' => 'لا يحتوي عنوان العميل على موقع على الخريطة، لا يمكن إرساله لشركة التوصيل.',
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
        'order_created' => 'تم إرسال الطلب لشركة التوصيل',
        'pending_driver_acceptance' => 'بانتظار قبول المندوب',
        'driver_accepted' => 'تم تعيين المندوب',
        'pending_order_preparation' => 'بانتظار تجهيز الطلب',
        'arrived_to_pickup' => 'وصل المندوب للمتجر',
        'order_picked_up' => 'في الطريق',
        'arrived_to_dropoff' => 'وصل الموقع',
        'cancellation_processing' => 'جارٍ إلغاء التوصيل',
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
        'pay'     => 'إكمال الدفع',
        'cancel'  => 'إلغاء الطلب',
        'contact_support' => 'تواصل مع الدعم',
        'visit_store' => 'زيارة المتجر',
    ],

    // ملخص الدفع (شاشة تفاصيل الطلب)
    'summary' => [
        'subtotal'     => 'المجموع الفرعي',
        'delivery_fee' => 'رسوم التوصيل',
        'express_fee'  => 'رسوم التوصيل الفوري',
        'discount'     => 'الخصم',
        'tax'          => 'ضريبة القيمة المضافة (:rate%)',
        'total'        => 'الإجمالي',
        'free'         => 'مجاني',
    ],

    // تبويبات "طلباتي" وعنوان البطاقة
    'tabs' => [
        'active'  => 'الحالية',
        'history' => 'السابقة',
    ],
    'location' => [
        'full'     => ':city، :district',
        'district' => 'حي :district',
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
        'order_created' => ['label' => 'تم إرسال الطلب لشركة التوصيل', 'description' => 'تم إرسال طلبك لشركة التوصيل وسيتم تعيين مندوب قريباً.'],
        'pending_driver_acceptance' => ['label' => 'بانتظار قبول المندوب', 'description' => 'نبحث عن أقرب مندوب لاستلام طلبك.'],
        'driver_accepted' => ['label' => 'تم تعيين المندوب', 'description' => 'قبل المندوب طلبك وهو في طريقه للمتجر.'],
        'pending_order_preparation' => ['label' => 'بانتظار تجهيز الطلب', 'description' => 'المندوب بانتظار تسليم طلبك من المتجر.'],
        'arrived_to_pickup' => ['label' => 'وصل المندوب للمتجر', 'description' => 'وصل المندوب للمتجر لاستلام طلبك.'],
        'order_picked_up' => ['label' => 'في الطريق', 'description' => 'استلم المندوب طلبك وهو في الطريق إليك.'],
        'arrived_to_dropoff' => ['label' => 'وصل الموقع', 'description' => 'وصل المندوب إلى موقعك.'],
        'cancellation_processing' => ['label' => 'جارٍ إلغاء التوصيل', 'description' => 'نعالج إلغاء توصيل طلبك.'],
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
