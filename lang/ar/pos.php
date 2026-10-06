<?php

/** Arabic counterpart of lang/en/pos.php — keep both in sync. */
return [
    'tier' => [
        'retail' => 'مفرق',
        'wholesale' => 'جملة',
        'vip' => 'VIP',
    ],

    'errors' => [
        'price_tier_locked' => 'غير مسموح لك بتغيير لائحة الأسعار.',
        'discount_not_allowed' => 'الحسم غير مسموح على «:product».',
        'discount_exceeds_line' => 'الحسم على «:product» أكبر من قيمة السطر.',
        'discount_limit' => 'حسم :pct% على «:product» يتجاوز الحد المسموح لك وهو :limit%.',
        'cart_discount_exceeds' => 'الحسم على الفاتورة أكبر من مجموع الأصناف التي تسمح بالحسم.',
    ],

    'receipt' => [
        'price_list' => 'لائحة الأسعار',
        'item_discounts' => 'حسم الأصناف',
        'cart_discount' => 'حسم على الفاتورة',
    ],

    'settings' => [
        'cashier_can_change_price_tier' => 'يمكن للكاشير تغيير لائحة الأسعار (مفرق / جملة / VIP)',
    ],

    'users' => [
        'max_discount_hint' => '0 = استخدم الحد الافتراضي للمتجر من الإعدادات. لا حدّ على المدراء.',
    ],

    'ui' => [
        'price_list' => 'لائحة الأسعار',
        'tier_locked' => 'المدير فقط يستطيع تغيير لائحة الأسعار.',
        'retail' => 'مفرق',
        'wholesale' => 'جملة',
        'vip' => 'VIP',
        'discount' => 'حسم',
        'cart_discount' => 'حسم على الفاتورة',
        'discount_for' => 'حسم على :name',
        'by_percent' => 'نسبة مئوية',
        'by_amount' => 'مبلغ (دولار)',
        'value' => 'القيمة',
        'apply' => 'تطبيق',
        'remove_discount' => 'إزالة الحسم',
        'cancel' => 'إلغاء',
        'limit_hint' => 'الحد المسموح لك :limit%.',
        'not_allowed' => 'الحسم غير مسموح على هذا المنتج.',
        'invalid_number' => 'أدخل رقماً يساوي 0 أو أكبر.',
        'over_limit' => 'الحسم على «:name» سيصبح :pct%، وهو أكبر من حدّك :limit%.',
        'over_line' => 'الحسم على «:name» أكبر من قيمة السطر.',
        'cart_over_pool' => 'الحسم على الفاتورة أكبر من مجموع الأصناف التي تسمح بالحسم.',
        'no_discount_product' => '«:name» لا يسمح بالحسم.',
        'below_cost' => 'أقل من الكلفة',
        'of_which_cart' => 'حسم على الفاتورة',
        'vat' => 'ض.ق.م',
        'fix_discounts' => 'صحّح الحسومات أعلاه قبل الدفع.',
    ],
];
