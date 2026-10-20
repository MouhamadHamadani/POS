<?php

/** Arabic counterpart of lang/en/features.php — keep both in sync (LangParityTest enforces it). */
return [
    'separator' => '، ',

    // One per group in config/features.php.
    'groups' => [
        'core' => 'مشمولة في كل الخطط',
        'sales' => 'المبيعات',
        'customers' => 'الزبائن',
        'purchasing' => 'المشتريات',
        'inventory' => 'المخزون',
        'finance' => 'المالية',
        'reports' => 'التقارير',
        'receipts' => 'الإيصالات',
    ],

    // One per key in config/features.php, dots written as underscores.
    'labels' => [
        'auth' => 'تسجيل الدخول والملف الشخصي',
        'pos' => 'نقطة البيع',
        'shifts' => 'الورديات وصندوق النقد',
        'receipts' => 'الإيصالات والفواتير',
        'sales_history' => 'سجل المبيعات',
        'products' => 'المنتجات',
        'categories' => 'الفئات',
        'users' => 'المستخدمون',
        'dashboard' => 'لوحة التحكم',
        'reports' => 'التقارير',
        'reports_daily_sales' => 'تقرير المبيعات اليومية',
        'reports_sales_by_product' => 'تقرير المبيعات حسب المنتج',
        'reports_stock_levels' => 'تقارير مستويات المخزون والمخزون بتاريخ',
        'reports_profit_loss' => 'تقرير الأرباح والخسائر',
        'returns' => 'المرتجعات والاسترداد',
        'loyalty' => 'الزبائن والولاء',
        'suppliers' => 'الموردون وأوامر الشراء',
        'receipts_custom_templates' => 'قوالب الإيصالات المخصصة',
        'expenses' => 'المصاريف',
        'stock_movements' => 'سجل حركة المخزون',
    ],

    'errors' => [
        'requires' => 'لا يمكن تفعيل :feature: يحتاج إلى :missing وهو غير مفعّل.',
        'required_by' => 'لا يمكن إيقاف :feature: لأن :dependents ما زالت تعتمد عليه. أوقفها أولاً.',
    ],

    // Settings > Plan & Features
    'section' => 'الميزات',
    'help' => 'تتبع كل ميزة الخطة ما لم يكن لهذا التثبيت إعداد خاص بها. لا يستطيع حساب مدير النظام الخاص بالعميل رؤية هذا.',
    'save' => 'حفظ الميزات',
    'reset' => 'إعادة إلى إعدادات الخطة',
    'reset_confirm' => 'إزالة كل الاستثناءات واتباع خطة :plan تماماً؟',
    'reset_none' => 'هذا التثبيت يتبع خطته بالفعل.',
    'always_included' => 'مشمولة في كل الخطط',
    'blocked_needs' => 'يحتاج إلى :missing',
    'source' => [
        'plan' => 'الخطة',
        'override' => 'استثناء',
        'dependency' => 'محجوبة',
        'core' => 'مفعّلة دائماً',
        'demo' => 'تجريبي',
    ],
    'on' => 'مفعّلة',
    'off' => 'متوقفة',

    // Changing the plan: the diff shown before it is saved.
    'plan_change_title' => 'الانتقال إلى :plan يغيّر في هذا التثبيت:',
    'turns_on' => 'يفعّل',
    'turns_off' => 'يوقف',
    'no_changes' => 'لا تغيير في الميزات.',
    'confirm_plan' => 'تأكيد وحفظ الخطة',
];
