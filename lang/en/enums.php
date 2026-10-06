<?php

/** English source for lang/ar/enums.php — keep both in sync (LangParityTest enforces it). */
return [
    'role' => [
        'super_admin' => 'Super Admin',
        'admin' => 'Admin',
        'manager' => 'Manager',
        'cashier' => 'Cashier',
        'stock' => 'Stock Keeper',
    ],
    'customer_group' => [
        'retail' => 'Retail',
        'wholesale' => 'Wholesale',
        'vip' => 'VIP',
    ],
    'payment_method' => [
        'cash_usd' => 'Cash (USD)',
        'cash_lbp' => 'Cash (LBP)',
        'card' => 'Card',
        'bank_transfer' => 'Bank transfer',
        'other' => 'Other',
        'cheque' => 'Cheque',
    ],
    'purchase_status' => [
        'draft' => 'Draft',
        'sent' => 'Sent',
        'partial' => 'Partial',
        'received' => 'Received',
        'closed' => 'Closed',
        'cancelled' => 'Cancelled',
    ],
    'payment_terms' => [
        'NET15' => 'NET15',
        'NET30' => 'NET30',
        'NET60' => 'NET60',
        'COD' => 'COD',
        'prepaid' => 'prepaid',
    ],
    'return_status' => [
        'pending' => 'Pending',
        'approved' => 'Approved',
        'completed' => 'Completed',
        'rejected' => 'Rejected',
    ],
    'return_reason' => [
        'defective' => 'Defective',
        'wrong_item' => 'Wrong item',
        'customer_preference' => 'Customer changed mind',
        'other' => 'Other',
    ],
    'stock_status' => [
        'out' => 'Out',
        'low' => 'Low',
        'ok' => 'OK',
    ],
    'product_type' => [
        'simple' => 'Simple',
        'service' => 'Service (no stock)',
        'bundle' => 'Bundle',
        'variant' => 'Variant parent',
    ],
    'unit' => [
        'pcs' => 'pcs',
        'kg' => 'kg',
        'g' => 'g',
        'L' => 'L',
        'mL' => 'mL',
        'box' => 'box',
        'pair' => 'pair',
        'set' => 'set',
        'm' => 'm',
    ],
    'backup_frequency' => [
        'off' => 'Off',
        'daily' => 'Daily',
        'weekly' => 'Weekly',
    ],
];
