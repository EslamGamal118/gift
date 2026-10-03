<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines contain the default error messages used by
    | the validator class. Some of these rules have multiple versions such
    | as the size rules. Feel free to tweak each of these messages here.
    |
    */

    'accepted' => 'يجب الموافقة على :attribute.',
    'accepted_if' => 'يجب الموافقة على :attribute عندما يكون :other هو :value.',
    'active_url' => ':attribute ليس رابطًا صالحًا.',
    'after' => 'يجب أن يكون :attribute تاريخًا بعد :date.',
    'after_or_equal' => 'يجب أن يكون :attribute تاريخًا بعد أو يساوي :date.',
    'alpha' => 'يجب أن يحتوي :attribute على أحرف فقط.',
    'alpha_dash' => 'يجب أن يحتوي :attribute على أحرف وأرقام وشرطات وشرطات سفلية فقط.',
    'alpha_num' => 'يجب أن يحتوي :attribute على أحرف وأرقام فقط.',
    'array' => 'يجب أن يكون :attribute مصفوفة.',
    'ascii' => 'يجب أن يحتوي :attribute على أحرف ورموز أحادية البايت فقط.',
    'before' => 'يجب أن يكون :attribute تاريخًا قبل :date.',
    'before_or_equal' => 'يجب أن يكون :attribute تاريخًا قبل أو يساوي :date.',
    'between' => [
        'array' => 'يجب أن يحتوي :attribute على عدد عناصر بين :min و :max.',
        'file' => 'يجب أن يكون حجم :attribute بين :min و :max كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة :attribute بين :min و :max.',
        'string' => 'يجب أن يكون عدد أحرف :attribute بين :min و :max.',
    ],
    'boolean' => 'يجب أن تكون قيمة :attribute صحيحة أو خاطئة (true/false).',
    'can' => 'يحتوي :attribute على قيمة غير مصرّح بها.',
    'confirmed' => 'تأكيد :attribute غير متطابق.',
    'current_password' => 'كلمة المرور غير صحيحة.',
    'date' => ':attribute ليس تاريخًا صالحًا.',
    'date_equals' => 'يجب أن يكون :attribute تاريخًا مساويًا لـ :date.',
    'date_format' => 'لا يتطابق :attribute مع الصيغة :format.',
    'decimal' => 'يجب أن يحتوي :attribute على :decimal من المنازل العشرية.',
    'declined' => 'يجب رفض :attribute.',
    'declined_if' => 'يجب رفض :attribute عندما يكون :other هو :value.',
    'different' => 'يجب أن يكون :attribute مختلفًا عن :other.',
    'digits' => 'يجب أن يتكون :attribute من :digits أرقام.',
    'digits_between' => 'يجب أن يتكون :attribute من عدد أرقام بين :min و :max.',
    'dimensions' => 'أبعاد الصورة في :attribute غير صالحة.',
    'distinct' => 'يحتوي :attribute على قيمة مكررة.',
    'doesnt_end_with' => 'يجب ألّا ينتهي :attribute بأحد القيم التالية: :values.',
    'doesnt_start_with' => 'يجب ألّا يبدأ :attribute بأحد القيم التالية: :values.',
    'email' => 'يجب أن يكون :attribute بريدًا إلكترونيًا صالحًا.',
    'ends_with' => 'يجب أن ينتهي :attribute بأحد القيم التالية: :values.',
    'enum' => 'القيمة المحددة في :attribute غير صالحة.',
    'exists' => 'القيمة المحددة في :attribute غير موجودة.',
    'extensions' => 'يجب أن يكون امتداد :attribute أحد الامتدادات التالية: :values.',
    'file' => 'يجب أن يكون :attribute ملفًا.',
    'filled' => 'يجب أن يحتوي :attribute على قيمة.',
    'gt' => [
        'array' => 'يجب أن يحتوي :attribute على أكثر من :value عنصر.',
        'file' => 'يجب أن يكون حجم :attribute أكبر من :value كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة :attribute أكبر من :value.',
        'string' => 'يجب أن يكون عدد أحرف :attribute أكبر من :value.',
    ],
    'gte' => [
        'array' => 'يجب أن يحتوي :attribute على :value عنصر أو أكثر.',
        'file' => 'يجب أن يكون حجم :attribute أكبر من أو يساوي :value كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة :attribute أكبر من أو تساوي :value.',
        'string' => 'يجب أن يكون عدد أحرف :attribute أكبر من أو يساوي :value.',
    ],
    'hex_color' => 'يجب أن يكون :attribute لونًا بصيغة hex صالحة.',
    'image' => 'يجب أن يكون :attribute صورة.',
    'in' => 'القيمة المحددة في :attribute غير صالحة.',
    'in_array' => 'يجب أن يكون :attribute موجودًا في :other.',
    'integer' => 'يجب أن يكون :attribute عددًا صحيحًا.',
    'ip' => 'يجب أن يكون :attribute عنوان IP صالحًا.',
    'ipv4' => 'يجب أن يكون :attribute عنوان IPv4 صالحًا.',
    'ipv6' => 'يجب أن يكون :attribute عنوان IPv6 صالحًا.',
    'json' => 'يجب أن يكون :attribute نصًا بصيغة JSON صالحة.',
    'lowercase' => 'يجب أن يكون :attribute بأحرف صغيرة.',
    'lt' => [
        'array' => 'يجب أن يحتوي :attribute على أقل من :value عنصر.',
        'file' => 'يجب أن يكون حجم :attribute أصغر من :value كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة :attribute أصغر من :value.',
        'string' => 'يجب أن يكون عدد أحرف :attribute أقل من :value.',
    ],
    'lte' => [
        'array' => 'يجب ألّا يحتوي :attribute على أكثر من :value عنصر.',
        'file' => 'يجب أن يكون حجم :attribute أصغر من أو يساوي :value كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة :attribute أصغر من أو تساوي :value.',
        'string' => 'يجب أن يكون عدد أحرف :attribute أقل من أو يساوي :value.',
    ],
    'mac_address' => 'يجب أن يكون :attribute عنوان MAC صالحًا.',
    'max' => [
        'array' => 'يجب ألّا يحتوي :attribute على أكثر من :max عنصر.',
        'file' => 'يجب ألّا يتجاوز حجم :attribute :max كيلوبايت.',
        'numeric' => 'يجب ألّا تتجاوز قيمة :attribute :max.',
        'string' => 'يجب ألّا يتجاوز عدد أحرف :attribute :max حرفًا.',
    ],
    'max_digits' => 'يجب ألّا يتجاوز :attribute :max أرقام.',
    'mimes' => 'يجب أن يكون :attribute ملفًا من نوع: :values.',
    'mimetypes' => 'يجب أن يكون :attribute ملفًا من نوع: :values.',
    'min' => [
        'array' => 'يجب أن يحتوي :attribute على :min عنصر على الأقل.',
        'file' => 'يجب ألّا يقل حجم :attribute عن :min كيلوبايت.',
        'numeric' => 'يجب ألّا تقل قيمة :attribute عن :min.',
        'string' => 'يجب ألّا يقل عدد أحرف :attribute عن :min حرفًا.',
    ],
    'min_digits' => 'يجب أن يتكون :attribute من :min أرقام على الأقل.',
    'missing' => 'يجب ألّا يكون :attribute موجودًا.',
    'missing_if' => 'يجب ألّا يكون :attribute موجودًا عندما يكون :other هو :value.',
    'missing_unless' => 'يجب ألّا يكون :attribute موجودًا إلا إذا كان :other هو :value.',
    'missing_with' => 'يجب ألّا يكون :attribute موجودًا عند وجود :values.',
    'missing_with_all' => 'يجب ألّا يكون :attribute موجودًا عند وجود :values جميعها.',
    'multiple_of' => 'يجب أن يكون :attribute من مضاعفات :value.',
    'not_in' => 'القيمة المحددة في :attribute غير صالحة.',
    'not_regex' => 'صيغة :attribute غير صالحة.',
    'numeric' => 'يجب أن يكون :attribute رقمًا.',
    'password' => [
        'letters' => 'يجب أن تحتوي :attribute على حرف واحد على الأقل.',
        'mixed' => 'يجب أن تحتوي :attribute على حرف كبير وحرف صغير على الأقل.',
        'numbers' => 'يجب أن تحتوي :attribute على رقم واحد على الأقل.',
        'symbols' => 'يجب أن تحتوي :attribute على رمز واحد على الأقل.',
        'uncompromised' => 'ظهرت :attribute المدخلة في تسريب بيانات سابق. يرجى اختيار :attribute مختلفة.',
    ],
    'present' => 'يجب أن يكون :attribute موجودًا.',
    'present_if' => 'يجب أن يكون :attribute موجودًا عندما يكون :other هو :value.',
    'present_unless' => 'يجب أن يكون :attribute موجودًا إلا إذا كان :other هو :value.',
    'present_with' => 'يجب أن يكون :attribute موجودًا عند وجود :values.',
    'present_with_all' => 'يجب أن يكون :attribute موجودًا عند وجود :values جميعها.',
    'prohibited' => ':attribute غير مسموح به.',
    'prohibited_if' => ':attribute غير مسموح به عندما يكون :other هو :value.',
    'prohibited_unless' => ':attribute غير مسموح به إلا إذا كان :other ضمن :values.',
    'prohibits' => 'وجود :attribute يمنع وجود :other.',
    'regex' => 'صيغة :attribute غير صالحة.',
    'required' => ':attribute مطلوب.',
    'required_array_keys' => 'يجب أن يحتوي :attribute على مدخلات لـ: :values.',
    'required_if' => ':attribute مطلوب عندما يكون :other هو :value.',
    'required_if_accepted' => ':attribute مطلوب عند الموافقة على :other.',
    'required_unless' => ':attribute مطلوب إلا إذا كان :other ضمن :values.',
    'required_with' => ':attribute مطلوب عند وجود :values.',
    'required_with_all' => ':attribute مطلوب عند وجود :values جميعها.',
    'required_without' => ':attribute مطلوب عند عدم وجود :values.',
    'required_without_all' => ':attribute مطلوب عند عدم وجود أيٍّ من :values.',
    'same' => 'يجب أن يتطابق :attribute مع :other.',
    'size' => [
        'array' => 'يجب أن يحتوي :attribute على :size عنصر.',
        'file' => 'يجب أن يكون حجم :attribute :size كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة :attribute :size.',
        'string' => 'يجب أن يتكون :attribute من :size حرفًا.',
    ],
    'starts_with' => 'يجب أن يبدأ :attribute بأحد القيم التالية: :values.',
    'string' => 'يجب أن يكون :attribute نصًا.',
    'timezone' => 'يجب أن يكون :attribute منطقة زمنية صالحة.',
    'unique' => ':attribute مستخدم من قبل.',
    'uploaded' => 'فشل رفع :attribute.',
    'uppercase' => 'يجب أن يكون :attribute بأحرف كبيرة.',
    'url' => 'يجب أن يكون :attribute رابطًا صالحًا.',
    'ulid' => 'يجب أن يكون :attribute بصيغة ULID صالحة.',
    'uuid' => 'يجب أن يكون :attribute بصيغة UUID صالحة.',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Here you may specify custom validation messages for attributes using the
    | convention "attribute.rule" to name the lines. This makes it quick to
    | specify a specific custom language line for a given attribute rule.
    |
    */

    'custom' => [
        'attribute-name' => [
            'rule-name' => 'custom-message',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    |
    | The following language lines are used to swap our attribute placeholder
    | with something more reader friendly such as "E-Mail Address" instead
    | of "email". This simply helps us make our message more expressive.
    |
    */

    'locales' => [
        'ar' => 'العربية',
        'en' => 'الإنجليزية',
    ],

    'attributes' => [
        // الحساب والمصادقة
        'name' => 'الاسم',
        'email' => 'البريد الإلكتروني',
        'phone' => 'رقم الجوال',
        'mobile' => 'رقم الجوال',
        'otp' => 'رمز التحقق',
        'code' => 'رمز التحقق',
        'password' => 'كلمة المرور',
        'password_confirmation' => 'تأكيد كلمة المرور',
        'account_type' => 'نوع الحساب',
        'role' => 'نوع الحساب',
        'user_type' => 'نوع الحساب',
        'avatar' => 'الصورة الشخصية',
        'profile_image' => 'الصورة الشخصية',
        'fcm_token' => 'رمز الإشعارات',
        'device_type' => 'نوع الجهاز',
        'device_id' => 'معرّف الجهاز',
        'device_name' => 'اسم الجهاز',

        // المتجر
        'store_name' => 'اسم المتجر',
        'store_phone' => 'جوال المتجر',
        'store_email' => 'البريد الإلكتروني للمتجر',
        'category' => 'التصنيف',
        'category_id' => 'التصنيف',
        'category_ids' => 'التصنيفات المفضلة',
        'categories' => 'التصنيفات',
        'description' => 'الوصف',
        'logo' => 'الشعار',
        'cover_image' => 'صورة الغلاف',
        'working_hours' => 'ساعات العمل',
        'commercial_register_file' => 'ملف السجل التجاري',
        'commercial_register' => 'السجل التجاري',
        'freelance_certificate' => 'وثيقة العمل الحر',
        'working_hours_is_open' => 'حالة يوم :day',
        'working_hours_from' => 'وقت الفتح ليوم :day',
        'working_hours_to' => 'وقت الإغلاق ليوم :day',

        // الفروع والمواقع
        'branch_name' => 'اسم الفرع',
        'address' => 'العنوان',
        'address_text' => 'العنوان',
        'latitude' => 'خط العرض',
        'longitude' => 'خط الطول',
        'is_main' => 'الفرع الرئيسي',
        'is_active' => 'الحالة',

        // الكابتن والمركبة
        'vehicle_type_id' => 'نوع المركبة',
        'vehicle_model' => 'موديل المركبة',
        'plate_number' => 'رقم اللوحة',
        'plate_number_file' => 'ملف لوحة المركبة',
        'plate_image' => 'صورة لوحة المركبة',
        'vehicle_image' => 'صورة المركبة',
        'driving_license' => 'رخصة القيادة',
        'plate_image_file' => 'صورة لوحة المركبة',
        'license_file' => 'ملف رخصة القيادة',
        'personal_photo' => 'الصورة الشخصية',

        // المتسوق الشخصي
        'national_id_number' => 'رقم الهوية الوطنية',
        'national_id_file' => 'ملف الهوية الوطنية',
        'national_id' => 'صورة الهوية الوطنية / الإقامة',
        'freelance_license_file' => 'ملف وثيقة العمل الحر',

        // البيانات البنكية
        'iban' => 'رقم الآيبان',
        'iban_certificate_file' => 'شهادة الآيبان',
        'iban_certificate' => 'شهادة الآيبان',
        // المنتجات والإضافات
        'product_name' => 'اسم المنتج',
        'final_amount' => 'المبلغ المدفوع',
        'unit_price' => 'سعر الوحدة',
        'actual_price' => 'السعر الفعلي',
        'shopper_fees' => 'أجرة المتسوق',
        'invoice_image' => 'صورة الفاتورة',
        'delivery_address' => 'عنوان التوصيل',
        'item_id' => 'المنتج',
        'store_rating' => 'تقييم المتجر',
        'store_comment' => 'تعليق المتجر',
        'products_rating' => 'تقييم المنتجات',
        'products_comment' => 'تعليق المنتجات',
        'alternatives' => 'البدائل',
        'alternative_id' => 'البديل',
        'is_approved' => 'الموافقة',
        'reason' => 'السبب',
        'product_image' => 'صورة المنتج',
        'addon_name' => 'اسم الإضافة',
        'addon_image' => 'صورة الإضافة',
        'stock_quantity' => 'الكمية المتوفرة',
        'expiry_date' => 'تاريخ الانتهاء',
        'preparation_time' => 'وقت التحضير',
        // الكتالوج والبحث
        'keyword' => 'كلمة البحث',
        'q' => 'كلمة البحث',
        'type' => 'النوع',
        'favoritable_type' => 'نوع المفضلة',
        'favoritable_id' => 'عنصر المفضلة',
        'sort' => 'الترتيب',
        'open_now' => 'مفتوح الآن',
        'min_rating' => 'الحد الأدنى للتقييم',
        'within_km' => 'المسافة (كم)',
        'in_stock' => 'متوفر',
        'price_min' => 'الحد الأدنى للسعر',
        'price_max' => 'الحد الأعلى للسعر',
        'results_count' => 'عدد النتائج',
        'tab' => 'التبويب',
        'rating' => 'التقييم',



        // إتمام الطلب
        'location_name' => 'اسم الموقع',
        'city' => 'المدينة',
        'district' => 'الحي',
        'street' => 'الشارع',
        'building_number' => 'رقم المبنى',
        'is_default' => 'العنوان الافتراضي',
        'address_id' => 'عنوان التوصيل',
        'pickup_address_id' => 'عنوان الاستلام',
        'product_id' => 'المنتج',
        'addon_ids' => 'الإضافات',
        'delivery_type' => 'نوع التوصيل',
        'delivery_date' => 'تاريخ التوصيل',
        'delivery_slot_id' => 'فترة التوصيل',
        'gift_message' => 'رسالة الهدية',
        'promo_code' => 'كود الخصم',
        'order_id' => 'الطلب',
        'payment_method' => 'طريقة الدفع',
        'cancellation_reason' => 'سبب الإلغاء',
        'captain_id' => 'كابتن التوصيل',
        'date_range' => 'الفترة الزمنية',
        'date_from' => 'من تاريخ',
        'date_to' => 'إلى تاريخ',

        // الطلبات الخاصة (المتسوق الشخصي)
        'items' => 'المنتجات',
        'items.*.product_name' => 'اسم المنتج',
        'items.*.description' => 'وصف المنتج',
        'items.*.quantity' => 'الكمية',
        'items.*.expected_price_min' => 'الحد الأدنى للسعر المتوقع',
        'items.*.expected_price_max' => 'الحد الأعلى للسعر المتوقع',
        'items.*.images' => 'الصور المرجعية',
        'items.*.images.*' => 'الصورة المرجعية',
        'delivery_at' => 'وقت التوصيل',
        'budget_min' => 'الحد الأدنى للميزانية',
        'budget_max' => 'الحد الأعلى للميزانية',
        'shopper_id' => 'المتسوق الشخصي',
        'mode' => 'طريقة التعيين',
        'address.location_name' => 'اسم الموقع',
        'address.city' => 'المدينة',
        'address.district' => 'الحي',
        'address.street' => 'الشارع',
        'address.building_number' => 'رقم المبنى',
        'address.phone' => 'رقم الجوال',
        'address.latitude' => 'خط العرض',
        'address.longitude' => 'خط الطول',
        'available_only' => 'المتاحون فقط',
        'confirmation_notes' => 'ملاحظات التأكيد',

        // عام
        'image' => 'الصورة',
        'file' => 'الملف',
        'status' => 'الحالة',
        'status_type' => 'نوع الطلبات',
        'period' => 'الفترة',
        'orders_limit' => 'عدد الطلبات',
        'notifications_limit' => 'عدد الإشعارات',
        'rejection_reason' => 'سبب الرفض',
        'date' => 'التاريخ',
        'time' => 'الوقت',
        'price' => 'السعر',
        'quantity' => 'الكمية',
        'recipient_name' => 'اسم المستلم',
        'recipient_phone' => 'جوال المستلم',
        'recipient_email' => 'بريد المستلم',
        'gateway' => 'طريقة الدفع',
        'amount' => 'المبلغ',
        'total' => 'الإجمالي',
        'notes' => 'الملاحظات',
        'tab' => 'التبويب',
        'page' => 'الصفحة',
        'per_page' => 'عدد العناصر في الصفحة',
        'search' => 'البحث',
    ],

];
