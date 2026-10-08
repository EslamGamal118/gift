<?php

return [

    /*
    |--------------------------------------------------------------------------
    | نصوص إشعارات التطبيق (Push + صندوق الوارد)
    |--------------------------------------------------------------------------
    |
    | المتغيرات: :order_number, :store_name, :reason, :eta_max,
    | :customer_name, :total, :currency
    |
    */

    // العميل - تغيّر حالة الطلب
    'order_accepted_title'         => 'تم قبول الطلب :order_number',
    'order_accepted_body'          => 'قبل متجر :store_name طلبك وسيبدأ بتجهيزه قريبًا.',

    'order_processing_title'       => 'جارٍ تجهيز طلبك',
    'order_processing_body'        => 'يقوم متجر :store_name الآن بتجهيز الطلب :order_number.',

    'order_ready_title'            => 'طلبك جاهز',
    'order_ready_body'             => 'تم تجهيز الطلب :order_number وهو بانتظار كابتن التوصيل.',

    'order_out_for_delivery_title' => 'طلبك في الطريق إليك',
    'order_out_for_delivery_body'  => 'تم تسليم الطلب :order_number لكابتن التوصيل وهو في طريقه إليك.',

    'order_delivered_title'        => 'تم توصيل الطلب',
    'order_delivered_body'         => 'تم توصيل الطلب :order_number. نتمنى أن تنال الهدية إعجابك!',

    'order_cancelled_title'        => 'تم إلغاء الطلب :order_number',
    'order_cancelled_body'         => 'تم إلغاء طلبك من قبل متجر :store_name. السبب: :reason',

    // طلبات المتاجر التي توصلها شركة التوصيل (الشروق)
    'order_status_changed_title'   => 'تحديث على طلبك :order_number',
    'order_status_changed_body'    => 'حالة طلبك :order_number الآن: :status.',
    'order_driver_accepted_title'  => 'تم تعيين مندوب لطلبك',
    'order_driver_accepted_body'   => 'قبل المندوب الطلب :order_number وهو في طريقه لاستلامه من متجر :store_name.',
    'order_picked_up_body'         => 'استلم المندوب الطلب :order_number من متجر :store_name وهو في طريقه إليك.',
    'order_arrived_title'          => 'وصل المندوب',
    'order_arrived_body'           => 'وصل المندوب بطلبك :order_number.',
    'order_cancellation_processing_title' => 'جارٍ إلغاء التوصيل',
    'order_cancellation_processing_body'  => 'جارٍ معالجة إلغاء توصيل الطلب :order_number.',
    'order_delivery_cancelled_title' => 'تم إلغاء التوصيل',
    'order_delivery_cancelled_body'  => 'تم إلغاء توصيل الطلب :order_number، وسيتواصل معك فريق الدعم.',

    // المتجر - طلب جديد مدفوع
    'new_order_title'              => 'طلب جديد :order_number',
    'new_order_body'               => 'قام :customer_name بإنشاء طلب جديد بقيمة :total :currency. يرجى قبول الطلب.',

    // الكباتن - طلب جاهز للتوصيل متاح لجميع الكباتن النشطين
    'delivery_request_title'       => 'طلب توصيل جديد :order_number',
    'delivery_request_body'        => 'طلب من :store_name إلى حي :district جاهز للاستلام. اقبله قبل كابتن آخر.',

    // المتسوق الشخصي - الطلبات الخاصة
    'custom_order_assigned_title'  => 'طلب خاص جديد :order_number',
    'custom_order_assigned_body'   => 'اختارك :customer_name لتنفيذ طلب خاص يحتوي على :items_count منتج. يرجى قبول الطلب.',
    'custom_order_bidding_title'   => 'طلب خاص مفتوح للعروض',
    'custom_order_bidding_body'    => 'طلب خاص جديد :order_number يحتوي على :items_count منتج بانتظار عرضك.',
    'custom_order_accepted_title'    => 'تم قبول طلبك',
    'custom_order_accepted_body'     => 'قام المتسوق :shopper_name بقبول طلبك :order_number.',
    'custom_order_in_progress_title' => 'بدأ التسوق',
    'custom_order_in_progress_body'  => 'بدأ المتسوق :shopper_name رحلة التسوق لطلبك :order_number.',
    'custom_order_waiting_for_payment_title' => 'تم شراء طلبك',
    'custom_order_waiting_for_payment_body'  => 'قام المتسوق :shopper_name بشراء طلبك :order_number. راجع الفاتورة وادفع لإتمام الطلب.',
    'custom_order_completed_title'   => 'تم توصيل طلبك',
    'custom_order_completed_body'    => 'تم توصيل طلبك :order_number بنجاح، بالعافية!',
    'custom_order_driver_accepted_title' => 'تم تعيين مندوب لطلبك',
    'custom_order_driver_accepted_body'  => 'قبل المندوب طلبك :order_number وهو في طريقه لاستلامه.',
    'custom_order_picked_up_title'   => 'طلبك في الطريق',
    'custom_order_picked_up_body'    => 'استلم المندوب طلبك :order_number.',
    'custom_order_arrived_title'     => 'وصل المندوب',
    'custom_order_arrived_body'      => 'وصل المندوب بطلبك :order_number.',
    'custom_order_cancellation_processing_title' => 'جارٍ إلغاء التوصيل',
    'custom_order_cancellation_processing_body'  => 'جارٍ معالجة إلغاء توصيل طلبك :order_number.',
    'custom_order_status_changed_title' => 'تحديث على طلبك :order_number',
    'custom_order_status_changed_body'  => 'حالة طلبك :order_number الآن: :status.',
    'custom_order_shopper_status_title' => 'الطلب :order_number: :status',
    'custom_order_shopper_status_body'  => 'طلب :customer_name رقم :order_number أصبح الآن: :status.',
    'custom_order_delivery_cancelled_title' => 'تم إلغاء التوصيل',
    'custom_order_delivery_cancelled_body'  => 'تم إلغاء توصيل طلبك :order_number، وسيتواصل معك فريق الدعم.',
    'custom_order_declined_title'    => 'اعتذر المتسوق عن طلبك',
    'custom_order_declined_body'     => 'اعتذر المتسوق :shopper_name عن تنفيذ طلبك :order_number. السبب: :reason',
    'custom_order_alternative_title' => 'اقتراح بديل لطلبك',
    'custom_order_alternative_body'  => 'اقترح المتسوق :shopper_name بديلًا لـ ":item_name": :product_name بسعر :price. راجع الاقتراح لقبوله أو رفضه.',
    'custom_order_paid_title'        => 'تم دفع الطلب :order_number',
    'custom_order_paid_body'         => 'دفع :customer_name مبلغ :amount للطلب :order_number.',
    'custom_order_alternatives_answered_title' => 'تمت مراجعة البدائل للطلب :order_number',
    'custom_order_alternatives_answered_body' => 'وافق :customer_name على :approved ورفض :rejected من البدائل التي اقترحتها للطلب :order_number.',

    // Online gifts
    'gift_purchased_title'            => 'هدية جديدة :order_number',
    'gift_purchased_body'             => 'تم شراء هدية جديدة :gift للمستلم :recipient_name رقم الجوال :recipient_phone مع رسالة: :message',
    'gift_purchased_body_no_message'  => 'تم شراء هدية جديدة :gift للمستلم :recipient_name رقم الجوال :recipient_phone',
    'gift_received_title'             => 'وصلتك هدية 🎁',
    'gift_received_body'              => 'أرسل لك :sender هدية: :gift',

    /*
    |--------------------------------------------------------------------------
    | شاشة الإشعارات
    |--------------------------------------------------------------------------
    */

    'inbox' => [
        'filters' => [
            'all'      => 'الكل',
            'orders'   => 'الطلبات',
            'payments' => 'المدفوعات',
            'system'   => 'النظام',
        ],
        'groups' => [
            'today'     => 'اليوم',
            'yesterday' => 'أمس',
            'older'     => 'سابقاً',
        ],
        'all_marked_read' => 'تم تحديد جميع الإشعارات كمقروءة.',
    ],

];
