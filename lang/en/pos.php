<?php

/**
 * Strings for the sell screen's price lists and discounts. Arabic counterpart:
 * lang/ar/pos.php — keep both in sync (tests/Unit/PosLangParityTest.php fails
 * if a key is missing from either).
 *
 * `ui` is handed to the sell screen as JSON and read with t(key, {vars});
 * `:name` placeholders are substituted in JS there, by Laravel everywhere else.
 */
return [
    'tier' => [
        'retail' => 'Retail',
        'wholesale' => 'Wholesale',
        'vip' => 'VIP',
    ],

    'errors' => [
        'price_tier_locked' => 'You are not allowed to change the price list.',
        'discount_not_allowed' => 'Discounts are not allowed on ":product".',
        'discount_exceeds_line' => 'The discount on ":product" is more than the line total.',
        'discount_limit' => 'A :pct% discount on ":product" is above your limit of :limit%.',
        'cart_discount_exceeds' => 'The cart discount is more than the total of the items that allow discounts.',
    ],

    'receipt' => [
        'price_list' => 'Price list',
        'item_discounts' => 'Item discounts',
        'cart_discount' => 'Cart discount',
    ],

    'settings' => [
        'cashier_can_change_price_tier' => 'Cashiers can switch the price list (retail / wholesale / VIP)',
    ],

    'users' => [
        'max_discount_hint' => '0 = use the store default from Settings. Managers and admins are not limited.',
    ],

    'ui' => [
        'price_list' => 'Price list',
        'tier_locked' => 'Only a manager can change the price list.',
        'retail' => 'Retail',
        'wholesale' => 'Wholesale',
        'vip' => 'VIP',
        'discount' => 'Discount',
        'cart_discount' => 'Cart discount',
        'discount_for' => 'Discount for :name',
        'by_percent' => 'Percent',
        'by_amount' => 'Amount (USD)',
        'value' => 'Value',
        'apply' => 'Apply',
        'remove_discount' => 'Remove discount',
        'cancel' => 'Cancel',
        'limit_hint' => 'Your limit is :limit%.',
        'not_allowed' => 'Discounts are not allowed on this product.',
        'invalid_number' => 'Enter a number of 0 or more.',
        'over_limit' => 'The discount on ":name" would be :pct%, above your limit of :limit%.',
        'over_line' => 'The discount on ":name" is more than the line total.',
        'cart_over_pool' => 'The cart discount is more than the items that allow discounts.',
        'no_discount_product' => '":name" does not allow discounts.',
        'below_cost' => 'Below cost',
        'of_which_cart' => 'Cart discount',
        'vat' => 'VAT',
        'fix_discounts' => 'Fix the discounts above before paying.',
    ],
];
