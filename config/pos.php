<?php

return [
    'business' => [
        'name' => env('POS_BUSINESS_NAME', 'POS Pro'),
        'country' => env('POS_COUNTRY', 'LB'),
        'timezone' => env('POS_TIMEZONE', 'Asia/Beirut'),
    ],

    'currency' => [
        'base' => env('POS_BASE_CURRENCY', 'USD'),
        'secondary' => env('POS_SECONDARY_CURRENCY', 'LBP'),
        'default_rate' => env('POS_DEFAULT_EXCHANGE_RATE', 90000),
        'lbp_rounding_step' => env('POS_LBP_ROUNDING_STEP', 1000),
    ],

    'tax' => [
        'default_rate' => env('POS_DEFAULT_TAX_RATE', 0.11), // Lebanon VAT 11%
        'inclusive' => env('POS_TAX_INCLUSIVE', false),
    ],

    'receipt' => [
        'width' => env('POS_RECEIPT_WIDTH', 80), // 58 or 80 mm
        'auto_print' => env('POS_AUTO_PRINT', true),
        'prefix' => env('POS_RECEIPT_PREFIX', 'REC-' . date('Y') . '-'),
    ],

    'invoice' => [
        'prefix' => env('POS_INVOICE_PREFIX', 'INV-' . date('Y') . '-'),
    ],

    'pos' => [
        'idle_timeout_min' => env('POS_IDLE_TIMEOUT_MIN', 30),
        'require_shift' => env('POS_REQUIRE_SHIFT', true),
        'max_cashier_discount_pct' => env('POS_MAX_CASHIER_DISCOUNT_PCT', 10),
        'show_cost_to_cashier' => env('POS_SHOW_COST_TO_CASHIER', false),
    ],

    'loyalty' => [
        'points_per_dollar' => env('POS_LOYALTY_POINTS_PER_DOLLAR', 1),
        'redemption_rate' => env('POS_LOYALTY_REDEMPTION_RATE', 100), // 100 points = $1
        'expiry_days' => env('POS_LOYALTY_EXPIRY_DAYS', 365),
        'tiers' => [
            'bronze' => 0,
            'silver' => 500,
            'gold' => 2000,
        ],
    ],

    'backup' => [
        'path' => storage_path('app/backups'),
        'frequency' => env('POS_BACKUP_FREQUENCY', 'daily'),
        'retention_days' => env('POS_BACKUP_RETENTION_DAYS', 30),
    ],

    'roles' => [
        'admin' => 'Administrator',
        'manager' => 'Manager',
        'cashier' => 'Cashier',
        'stock' => 'Stock Keeper',
    ],
];
