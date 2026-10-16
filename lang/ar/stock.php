<?php

/** Arabic counterpart of lang/en/stock.php — keep both in sync (LangParityTest enforces it). */
return [
    'title' => 'حركة المخزون',
    'cols' => [
        'date' => 'التاريخ / الوقت',
        'product' => 'المنتج',
        'sku' => 'SKU',
        'type' => 'النوع',
        'qty_in' => 'وارد',
        'qty_out' => 'صادر',
        'balance' => 'الرصيد بعد الحركة',
        'reference' => 'المرجع',
        'user' => 'المستخدم',
        'notes' => 'السبب / ملاحظات',
    ],
    'empty' => 'لا توجد حركات مخزون مطابقة.',
    'manual' => 'يدوي',
    'direction' => 'الاتجاه',
    'directions' => [
        'all' => 'وارد وصادر',
        'in' => 'وارد فقط',
        'out' => 'صادر فقط',
    ],
    'only_product' => 'المنتج: :name',
    'clear_product' => 'عرض كل المنتجات',
    'reset' => 'إعادة ضبط',
    'total_in' => 'إجمالي الوارد',
    'total_out' => 'إجمالي الصادر',
    'net' => 'صافي التغيّر',
    'full_history' => 'السجل الكامل مع الفلاتر',
];
