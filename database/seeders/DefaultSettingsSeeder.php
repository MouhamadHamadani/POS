<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class DefaultSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            // general
            ['general', 'business_name', 'POS Pro', 'string'],
            ['general', 'business_name_ar', 'بوس برو', 'string'],
            ['general', 'address', '', 'string'],
            ['general', 'phone', '', 'string'],
            ['general', 'email', '', 'string'],
            ['general', 'tax_number', '', 'string'],
            ['general', 'logo', '', 'string'],

            // currency
            ['currency', 'exchange_rate', '90000', 'float'],
            ['currency', 'lbp_rounding_step', '1000', 'float'],
            ['currency', 'base_currency', 'USD', 'string'],
            ['currency', 'secondary_currency', 'LBP', 'string'],

            // pos behavior
            ['pos', 'auto_print', '1', 'bool'],
            ['pos', 'require_shift', '1', 'bool'],
            ['pos', 'max_cashier_discount_pct', '10', 'float'],
            ['pos', 'idle_timeout_min', '30', 'int'],
            ['pos', 'pos_display_cost', '0', 'bool'],
            ['pos', 'require_customer_credit', '1', 'bool'],

            // receipt
            ['receipt', 'receipt_width', '80', 'int'],
            ['receipt', 'paper_width_char', '48', 'int'],
            ['receipt', 'receipt_header', "POS Pro\nThank you for your business", 'string'],
            ['receipt', 'receipt_footer', 'Visit us again!', 'string'],

            // numbering
            ['numbering', 'receipt_prefix', 'REC-' . date('Y') . '-', 'string'],
            ['numbering', 'receipt_counter', '0', 'int'],
            ['numbering', 'invoice_prefix', 'INV-' . date('Y') . '-', 'string'],
            ['numbering', 'invoice_counter', '0', 'int'],
            ['numbering', 'po_prefix', 'PO-' . date('Y') . '-', 'string'],
            ['numbering', 'po_counter', '0', 'int'],
            ['numbering', 'return_prefix', 'RTN-' . date('Y') . '-', 'string'],
            ['numbering', 'return_counter', '0', 'int'],

            // loyalty
            ['loyalty', 'points_per_dollar', '1', 'float'],
            ['loyalty', 'redemption_rate', '100', 'int'],
            ['loyalty', 'expiry_days', '365', 'int'],
            ['loyalty', 'bronze_threshold', '0', 'int'],
            ['loyalty', 'silver_threshold', '500', 'int'],
            ['loyalty', 'gold_threshold', '2000', 'int'],

            // backup
            ['backup', 'backup_frequency', 'daily', 'string'],
            ['backup', 'retention_days', '30', 'int'],
            ['backup', 'cloud_enabled', '0', 'bool'],

            // appearance
            ['appearance', 'language', 'en', 'string'],
            ['appearance', 'rtl', '0', 'bool'],
            ['appearance', 'dark_mode', '0', 'bool'],
        ];

        foreach ($defaults as [$group, $key, $value, $type]) {
            Setting::updateOrCreate(
                ['key' => $key],
                ['value' => $value, 'group' => $group, 'type' => $type]
            );
        }
    }
}
