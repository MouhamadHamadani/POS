<?php

/**
 * Pack / package / carton selling (client/tobacco, docs/packaging.md). English source for lang/ar/packaging.php:
 * keep both in sync (LangParityTest enforces it). Own file on purpose, so main's catalogs are never edited here.
 */
return [
    'unit' => [
        'pack' => 'Pack',
        'package' => 'Package',
        'carton' => 'Carton',
    ],
    'unit_x' => ':unit ×:n',
    'receive_note' => 'received :qty × :unit = :packs packs',

    'errors' => [
        'unit_not_supported' => ':product cannot be sold as :unit.',
        'receive_unit_not_available' => ':product is not set up with the unit :unit.',
        'simple_only' => 'Packaging works on simple products without variants only.',
        'barcode_taken' => 'This barcode is already used by :name.',
        'carton_equals_pack' => 'The carton barcode must be different from the pack barcode.',
        'carton_needs_package' => 'A carton needs "Also sold as a package" to be ticked first.',
    ],

    'report' => [
        'packs' => 'packs',
        'packages_n' => ':n packages',
        'cartons_n' => ':n cartons',
    ],

    'form' => [
        'section' => 'Packaging',
        'section_hint' => 'Sell this product as a pack, as a package of several packs, and as a carton. Stock is always counted in packs.',
        'simple_only_hint' => 'Packaging works on simple products only: not on variant, bundle or service products, or products with variants.',
        'has_package' => 'Also sold as a package of several packs',
        'package_size' => 'Packs per package',
        'package_prices' => 'Package prices',
        'price_retail' => 'Retail',
        'price_wholesale' => 'Wholesale',
        'price_vip' => 'VIP',
        'per_pack' => '= :price per pack',
        'has_carton' => 'Has a carton barcode',
        'carton_barcode' => 'Carton barcode',
        'carton_barcode_placeholder' => 'Scan the carton',
        'packages_per_carton' => 'Packages per carton',
        'packs_per_carton' => 'Packs per carton',
        'sell_carton' => 'Sell cartons at the POS',
        'sell_carton_hint' => 'Off: the carton barcode is only used to receive stock.',
        'carton_prices' => 'Carton prices',
        'carton_is_pack' => 'The carton barcode is the same as the pack barcode.',
    ],

    'receive' => [
        'unit' => 'Unit',
        'scan_carton' => 'Scan carton',
        'scan_carton_placeholder' => 'Scan a carton barcode to receive it',
        'carton_unknown' => 'No product has this carton barcode.',
        'carton_added' => ':name: receiving :n carton(s), :packs packs. Check the quantity and press Adjust stock.',
        'packs_added' => '= :packs packs',
        'default_reason' => 'Stock received',
        'cost_per_pack' => '= :cost per pack',
    ],

    // The sell screen reads these through the Alpine t() helper of the packaging mixin.
    'ui' => [
        'choose_title' => 'How is it sold?',
        'choose_hint' => 'Keys: 1 Pack · 2 Package · 3 Carton · Enter Pack · Esc cancel',
        'carton_not_sold' => 'This is a carton barcode: cartons are not sold here. Use stock receiving.',
        'line_dropped' => ':name was left out: its :unit is no longer sold.',
        'switch_unit' => 'Switch between pack and package',
        'per_unit' => ':price each',
    ],
];
