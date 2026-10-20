<?php

return [
    'business' => [
        'name' => env('POS_BUSINESS_NAME', 'LebaSouk'),
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

    /**
     * Demo build. A demo is a sandbox handed to a prospect: it runs off a
     * throwaway database seeded from resources/demo/demo-template.sqlite, resets
     * itself on every launch, and has every real-world side effect (backups,
     * exports, updater) switched off. Never set this on a client's machine —
     * App\Support\Demo::guardDatabasePath() refuses to boot a demo build that
     * resolves to a non-demo database path.
     */
    'demo_mode' => env('POS_DEMO_MODE', false),

    /** Shared password for the four seeded demo accounts. Demo builds only. */
    'demo_password' => env('POS_DEMO_PASSWORD', 'demo1234'),

    /**
     * The vendor's built-in super_admin, created on launch by
     * App\Support\SuperAdmin::ensure(). The password is a bcrypt HASH — never
     * the plain password — and has no default: with none, no account is made
     * and the login page says the install is not provisioned.
     */
    'super_admin' => [
        'username' => env('POS_SUPER_ADMIN_USERNAME', 'buildsyntax'),
        'name' => env('POS_SUPER_ADMIN_NAME', 'Build Syntax Support'),
        'password_hash' => env('POS_SUPER_ADMIN_PASSWORD_HASH'),
    ],

    /**
     * Pricing tiers, as published on the marketing site. One perpetual licence
     * per install: no key, no activation, no expiry — a super_admin picks the
     * tier on the Plan tab (Settings) and App\Support\License reads it back.
     *
     * `features` are the keys FeatureGate and License::allows() check; they are
     * named after the routes/sections they gate. `max_users` counts every
     * account that is not super_admin. `max_devices` is informational only in
     * this phase — the app is single-machine, so there is nothing to count yet.
     */
    'tiers' => [
        'starter' => [
            'max_users' => 1,
            'max_devices' => 1,
            'roles' => ['manager', 'cashier'],
            // Shift summary is the shift close screen, not a report route, so
            // it needs no key of its own — it ships on every tier.
            'features' => ['reports.daily_sales'],
        ],
        'standard' => [
            'max_users' => 3,
            'max_devices' => 3,
            'roles' => ['manager', 'cashier', 'stock', 'admin'],
            'features' => [
                'reports.daily_sales', 'reports.sales_by_product',
                'reports.stock_levels', 'reports.profit_loss',
                'returns', 'suppliers', 'loyalty',
            ],
        ],
        'premium' => [
            'max_users' => null, // null = unlimited
            'max_devices' => null,
            'roles' => ['manager', 'cashier', 'stock', 'admin'],
            'features' => [
                'reports.daily_sales', 'reports.sales_by_product',
                'reports.stock_levels', 'reports.profit_loss',
                'returns', 'suppliers', 'loyalty', 'receipts.custom_templates',
            ],
        ],
    ],

    /**
     * What License::tier() resolves to when the `tier` setting has never been
     * written — i.e. every install shipped before tiers existed. Premium keeps
     * those machines fully unlocked until someone sets their real tier. A new
     * install does not rely on this: SuperAdmin::ensure() writes `starter`.
     */
    'default_tier' => 'premium',

    'roles' => [
        'admin' => 'Administrator',
        'manager' => 'Manager',
        'cashier' => 'Cashier',
        'stock' => 'Stock Keeper',
    ],
];
