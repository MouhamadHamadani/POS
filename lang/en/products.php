<?php

/**
 * First translation catalog in this app. Every other view still holds hardcoded
 * English (see CLAUDE.md, "i18n / RTL — current gap"); this file exists because
 * the deleted-products view was the first screen added after that convention was
 * questioned. Extend it when the wider extraction happens rather than starting
 * a second pattern.
 */
return [
    'title' => 'Products',

    'trashed' => [
        'title' => 'Deleted Products',
        'nav' => 'Deleted',
        'note' => 'Read-only, and visible to super admins only. Deleted products keep their row so sales history stays intact, but they hold no barcode or SKU — those codes are free for a new product to use.',
        'name' => 'Name',
        'old_sku' => 'Old SKU',
        'old_barcode' => 'Old Barcode',
        'category' => 'Category',
        'deleted_at' => 'Deleted',
        'unknown_code' => 'Not recorded',
        'empty' => 'No deleted products.',
        'back' => 'Back to products',
    ],
];
