<?php

/** Arabic counterpart of lang/en/enums.php — keep both in sync (LangParityTest enforces it). */
return [
    'role' => [
        'super_admin' => 'دعم Build Syntax',
        'admin' => 'مدير النظام',
        'manager' => 'مدير',
        'cashier' => 'كاشير',
        'stock' => 'أمين المستودع',
    ],
    'activation_status' => [
        'trial' => 'تجريبية',
        'activated' => 'مفعّلة',
        'locked' => 'مقفلة',
    ],
    'activation_lock_reason' => [
        'trial_expired' => 'انتهت الفترة التجريبية',
        'manual' => 'مقفلة من المزوّد',
    ],
    'customer_group' => [
        'retail' => 'مفرق',
        'wholesale' => 'جملة',
        'vip' => 'VIP',
    ],
    'payment_method' => [
        'cash_usd' => 'نقداً (دولار)',
        'cash_lbp' => 'نقداً (ل.ل.)',
        'card' => 'بطاقة',
        'bank_transfer' => 'تحويل مصرفي',
        'other' => 'أخرى',
        'cheque' => 'شيك',
        'mixed' => 'مختلط',
        'credit' => 'دَين',
        'split' => 'مجزّأ',
    ],
    'purchase_status' => [
        'draft' => 'مسودة',
        'sent' => 'مُرسل',
        'partial' => 'جزئي',
        'received' => 'مستلم',
        'closed' => 'مغلق',
        'cancelled' => 'ملغى',
    ],
    'payment_terms' => [
        'NET15' => 'صافي 15 يوماً',
        'NET30' => 'صافي 30 يوماً',
        'NET60' => 'صافي 60 يوماً',
        'COD' => 'الدفع عند الاستلام',
        'prepaid' => 'مدفوع مسبقاً',
    ],
    'return_status' => [
        'pending' => 'قيد الانتظار',
        'approved' => 'تمت الموافقة',
        'completed' => 'مكتمل',
        'rejected' => 'مرفوض',
    ],
    'return_reason' => [
        'defective' => 'معيب',
        'wrong_item' => 'صنف خاطئ',
        'customer_preference' => 'غيّر الزبون رأيه',
        'other' => 'أخرى',
    ],
    'stock_status' => [
        'out' => 'نفد',
        'low' => 'منخفض',
        'ok' => 'جيد',
    ],
    'product_type' => [
        'simple' => 'عادي',
        'service' => 'خدمة (بدون مخزون)',
        'bundle' => 'حزمة',
        'variant' => 'منتج رئيسي بمتغيرات',
    ],
    'unit' => [
        'pcs' => 'قطعة',
        'kg' => 'كغ',
        'g' => 'غ',
        'L' => 'ل',
        'mL' => 'مل',
        'box' => 'علبة',
        'pair' => 'زوج',
        'set' => 'طقم',
        'm' => 'م',
    ],
    'backup_frequency' => [
        'off' => 'متوقف',
        'daily' => 'يومي',
        'weekly' => 'أسبوعي',
    ],
    'sale_status' => [
        'completed' => 'مكتمل',
        'voided' => 'ملغى',
        'refunded' => 'مُسترد',
        'partial_refund' => 'مُسترد جزئياً',
        'on_hold' => 'معلّق',
    ],
    'shift_status' => [
        'open' => 'مفتوحة',
        'closed' => 'مغلقة',
    ],
    'refund_method' => [
        'cash_usd' => 'نقداً (دولار)',
        'cash_lbp' => 'نقداً (ل.ل.)',
        'account' => 'رصيد في الحساب',
        'exchange' => 'استبدال',
    ],
    'item_condition' => [
        'good' => 'جيدة',
        'damaged' => 'تالفة',
        'expired' => 'منتهية الصلاحية',
    ],
    'loyalty_type' => [
        'earn' => 'اكتساب',
        'redeem' => 'استبدال',
        'expire' => 'انتهاء',
        'adjust' => 'تعديل',
    ],
    'loyalty_tier' => [
        'bronze' => 'برونزي',
        'silver' => 'فضي',
        'gold' => 'ذهبي',
    ],
];
