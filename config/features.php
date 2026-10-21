<?php

/**
 * The single source of truth for what a plan is made of.
 *
 * A plan (starter / standard / premium) is only a named preset of the feature keys
 * below, plus its seat and role limits. Each install can then deviate from its plan
 * by key (Setting `feature_overrides`), which only a super_admin can edit — see
 * App\Services\FeatureService for how a key resolves, and claude/feature-matrix.md
 * for why each key is here.
 *
 * `core` keys are always on and never toggleable; they exist so labels, grouping and
 * `requires` have something to point at. A toggleable key is off unless its plan or
 * an override switches it on, and it is also off whenever anything in `requires` is.
 * Labels are lang keys, not strings: `features.labels.<key with dots as underscores>`
 * and `features.groups.<group>` in lang/{en,ar}/features.php.
 *
 * Which plan an install is on is the `tier` setting; an install that has never had
 * one reads as `pos.default_tier` (premium), so installs that predate plans keep
 * everything.
 */

// What each plan adds on top of the one below it. Core keys are never listed.
$starter = ['expenses', 'stock_movements'];
$standard = [...$starter, 'reports.sales_by_product', 'reports.stock_levels', 'reports.profit_loss', 'returns', 'suppliers', 'loyalty', 'sales_channels'];
$premium = [...$standard, 'receipts.custom_templates'];

return [
    'features' => [
        // --- Core: always on --------------------------------------------------
        'auth' => ['group' => 'core', 'core' => true],
        'pos' => ['group' => 'core', 'core' => true],
        'shifts' => ['group' => 'core', 'core' => true],
        'receipts' => ['group' => 'core', 'core' => true],
        'sales_history' => ['group' => 'core', 'core' => true],
        'products' => ['group' => 'core', 'core' => true],
        'categories' => ['group' => 'core', 'core' => true],
        'users' => ['group' => 'core', 'core' => true],
        'dashboard' => ['group' => 'core', 'core' => true],
        'reports' => ['group' => 'core', 'core' => true],
        // In every plan and gated by nothing, so there is nothing to switch.
        'reports.daily_sales' => ['group' => 'core', 'core' => true, 'requires' => ['reports']],

        // --- Toggleable -------------------------------------------------------
        'reports.sales_by_product' => ['group' => 'reports', 'requires' => ['reports']],
        'reports.stock_levels' => ['group' => 'reports', 'requires' => ['reports']],
        // Nets expenses off gross profit; with Expenses off it would report a wrong profit.
        'reports.profit_loss' => ['group' => 'reports', 'requires' => ['reports', 'expenses']],
        'returns' => ['group' => 'sales', 'requires' => ['sales_history']],
        // On-site vs online-order tag on a sale (bookkeeping only; no integration). No route to gate.
        'sales_channels' => ['group' => 'sales', 'requires' => []],
        'loyalty' => ['group' => 'customers', 'requires' => []],
        'suppliers' => ['group' => 'purchasing', 'requires' => ['products']],
        'receipts.custom_templates' => ['group' => 'receipts', 'requires' => ['receipts']],
        'expenses' => ['group' => 'finance', 'requires' => ['shifts']],
        'stock_movements' => ['group' => 'inventory', 'requires' => ['products']],
    ],

    /** Display order of the groups on the Plan & Features tab. */
    'groups' => ['sales', 'customers', 'purchasing', 'inventory', 'finance', 'reports', 'receipts'],

    /**
     * `limits` are plan attributes, not features: `max_users` counts every account that
     * is not super_admin (null = unlimited), `roles` are the roles the plan lets exist,
     * and `max_devices` is informational (the app is single-machine, nothing counts it).
     * Overrides apply to features only, never to limits.
     */
    'plans' => [
        'starter' => [
            'limits' => ['max_users' => 1, 'max_devices' => 1, 'roles' => ['manager', 'cashier']],
            'features' => $starter,
        ],
        'standard' => [
            'limits' => ['max_users' => 3, 'max_devices' => 3, 'roles' => ['manager', 'cashier', 'stock', 'admin']],
            'features' => $standard,
        ],
        'premium' => [
            'limits' => ['max_users' => null, 'max_devices' => null, 'roles' => ['manager', 'cashier', 'stock', 'admin']],
            'features' => $premium,
        ],
    ],
];
