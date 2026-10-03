<?php

return [

    'checkout_created' => 'تم إنشاء طلب الهدية، أكمل الدفع.',
    'not_giftable'     => 'هذا المنتج غير متاح للإهداء.',
    'addon_unavailable' => 'هذه الإضافة غير متاحة لهذه الهدية.',
    'someone'          => 'شخص يهتم بك',

    // Snapshot on the order (online gifts have no delivery address)
    'order_location'   => 'هدية إلكترونية',
    'order_address'    => 'هدية إلكترونية إلى :name',

    // شاشة "هدايا اونلاين" (الهدايا المستلمة)
    'screen' => [
        'title' => 'هدايا اونلاين',
        'tabs'  => [
            'available' => 'المتاحة',
            'finished'  => 'المنتهية',
        ],
    ],
    'statuses' => [
        'active'   => 'متاحة',
        'redeemed' => 'تم الاستخدام',
        'expired'  => 'منتهية',
    ],

    // مسح رمز الهدية في المتجر
    'redeem' => [
        'done'             => 'تم استخدام الهدية بنجاح.',
        'not_found'        => 'رمز الهدية غير صحيح أو لا يخص متجرك.',
        'already_redeemed' => 'تم استخدام هذه الهدية مسبقًا.',
        'expired'          => 'انتهت صلاحية هذه الهدية.',
    ],

    // WhatsApp message to the recipient (paragraphs joined with blank lines)
    'whatsapp' => [
        'greeting' => 'مرحبًا :recipient 🎁',
        'body'     => 'أرسل لك :sender هدية: :gift',
        'message'  => 'رسالة الإهداء: «:message»',
        'link'     => 'حمّل التطبيق واستلم هديتك من هنا: :link',
        'code'     => 'رمز استلام الهدية: :code',
    ],

    // SMS برمز الاستلام إلى جوال المستلم
    'sms' => ':app: أرسل لك :sender هدية 🎁 رمز الاستلام: :code (هدايا اونلاين > استلام هدية)',

    // استلام هدية برمز
    'claim' => [
        'done'            => 'تم استلام الهدية بنجاح، تجدها الآن في هداياك المتاحة.',
        'invalid_code'    => 'رمز الهدية غير صحيح.',
        'already_claimed' => 'تم استلام هذه الهدية مسبقًا.',
        'own_gift'        => 'لا يمكنك استلام هدية أرسلتها بنفسك.',
        'expired'         => 'انتهت صلاحية هذه الهدية.',
        'code_attribute'  => 'رمز الهدية',
    ],

];
