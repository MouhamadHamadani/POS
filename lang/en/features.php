<?php

/** English source for lang/ar/features.php — keep both in sync (LangParityTest enforces it). */
return [
    'separator' => ', ',

    // One per group in config/features.php.
    'groups' => [
        'core' => 'Included in every plan',
        'sales' => 'Sales',
        'customers' => 'Customers',
        'purchasing' => 'Purchasing',
        'inventory' => 'Inventory',
        'finance' => 'Finance',
        'reports' => 'Reports',
        'receipts' => 'Receipts',
    ],

    // One per key in config/features.php, dots written as underscores.
    'labels' => [
        'auth' => 'Sign-in and profile',
        'pos' => 'Point of sale',
        'shifts' => 'Shifts and cash drawer',
        'receipts' => 'Receipts and invoices',
        'sales_history' => 'Sales history',
        'products' => 'Products',
        'categories' => 'Categories',
        'users' => 'Users',
        'dashboard' => 'Dashboard',
        'reports' => 'Reports',
        'reports_daily_sales' => 'Daily sales report',
        'reports_sales_by_product' => 'Sales by product report',
        'reports_stock_levels' => 'Stock levels and inventory-by-date reports',
        'reports_profit_loss' => 'Profit and loss report',
        'returns' => 'Returns and refunds',
        'loyalty' => 'Customers and loyalty',
        'suppliers' => 'Suppliers and purchase orders',
        'receipts_custom_templates' => 'Custom receipt templates',
        'expenses' => 'Expenses',
        'stock_movements' => 'Stock movements history',
    ],

    'errors' => [
        'requires' => 'Can\'t switch on :feature: it needs :missing, which is off.',
        'required_by' => 'Can\'t switch off :feature: :dependents still depend on it. Switch those off first.',
    ],

    // Settings > Plan & Features
    'section' => 'Features',
    'help' => 'Every feature follows the plan unless this install has its own setting for it. A client\'s own admin account cannot see this.',
    'save' => 'Save features',
    'reset' => 'Reset to plan defaults',
    'reset_confirm' => 'Remove every override and follow the :plan plan exactly?',
    'reset_none' => 'This install already follows its plan.',
    'always_included' => 'Included in every plan',
    'blocked_needs' => 'Needs :missing',
    'source' => [
        'plan' => 'Plan',
        'override' => 'Override',
        'dependency' => 'Blocked',
        'core' => 'Always on',
        'demo' => 'Demo',
    ],
    'on' => 'On',
    'off' => 'Off',

    // Changing the plan: the diff shown before it is saved.
    'plan_change_title' => 'Switching to :plan changes this install:',
    'turns_on' => 'Turns on',
    'turns_off' => 'Turns off',
    'no_changes' => 'No feature changes.',
    'confirm_plan' => 'Confirm and save plan',
];
