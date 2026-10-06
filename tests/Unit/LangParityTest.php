<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every English catalog has an Arabic twin with the same keys and placeholders,
 * and no Arabic value is blank or an untranslated copy of the English one.
 */
class LangParityTest extends TestCase
{
    /** Arabic == English is right for these (identifiers, brand terms, already-Arabic text). */
    private const SAME_AS_ENGLISH = [
        'pos.tier.vip', 'pos.ui.vip',
        'enums.customer_group.vip',
        'reports.cols.sku',
        'receipt.test_ar', 'receipt.demo_ar',
    ];

    /** Subtrees that are deliberately not mirrored (Arabic-only field names / framework scaffolding). */
    private const ONE_SIDED = ['validation.attributes', 'validation.custom'];

    private static function lang(string $locale, string $area): array
    {
        $flat = [];
        $walk = function (array $node, string $prefix) use (&$walk, &$flat) {
            foreach ($node as $key => $value) {
                is_array($value) ? $walk($value, "{$prefix}{$key}.") : $flat["{$prefix}{$key}"] = (string) $value;
            }
        };
        $walk(require __DIR__."/../../lang/{$locale}/{$area}.php", '');

        return self::withoutOneSided($flat, $area);
    }

    private static function withoutOneSided(array $flat, string $area): array
    {
        foreach (self::ONE_SIDED as $prefix) {
            foreach (array_keys($flat) as $key) {
                if (str_starts_with("{$area}.{$key}", $prefix.'.')) {
                    unset($flat[$key]);
                }
            }
        }

        return $flat;
    }

    private static function placeholders(string $s): array
    {
        preg_match_all('/:[a-zA-Z_]+/', $s, $m);
        sort($m[0]);

        return $m[0];
    }

    public static function areas(): array
    {
        $areas = [];
        foreach (glob(__DIR__.'/../../lang/en/*.php') as $file) {
            $areas[basename($file, '.php')] = [basename($file, '.php')];
        }

        return $areas;
    }

    #[DataProvider('areas')]
    public function test_arabic_catalog_exists_with_the_same_keys(string $area): void
    {
        $this->assertFileExists(__DIR__."/../../lang/ar/{$area}.php");

        $en = self::lang('en', $area);
        $ar = self::lang('ar', $area);

        $this->assertSame([], array_values(array_diff_key($en, $ar)), "{$area}: in en but missing from ar");
        $this->assertSame([], array_values(array_diff_key($ar, $en)), "{$area}: in ar but missing from en");
    }

    #[DataProvider('areas')]
    public function test_placeholders_match(string $area): void
    {
        $ar = self::lang('ar', $area);

        foreach (self::lang('en', $area) as $key => $english) {
            $this->assertSame(self::placeholders($english), self::placeholders($ar[$key] ?? ''), "{$area}.{$key}: placeholders differ");
        }
    }

    #[DataProvider('areas')]
    public function test_arabic_values_are_filled_in_and_translated(string $area): void
    {
        $en = self::lang('en', $area);

        foreach (self::lang('ar', $area) as $key => $arabic) {
            $this->assertNotSame('', trim($arabic), "{$area}.{$key} is empty in ar");

            if ($arabic === ($en[$key] ?? null) && ! in_array("{$area}.{$key}", self::SAME_AS_ENGLISH, true)) {
                // Pure identifiers (no letters of a translatable word) are fine; anything else is untranslated.
                $this->assertDoesNotMatchRegularExpression('/[a-z]{3,}/', $arabic, "{$area}.{$key} looks untranslated");
            }
        }
    }

    public function test_json_catalogs_have_the_same_keys_and_arabic_is_translated(): void
    {
        $en = json_decode(file_get_contents(__DIR__.'/../../lang/en.json'), true);
        $ar = json_decode(file_get_contents(__DIR__.'/../../lang/ar.json'), true);

        $this->assertSame([], array_values(array_diff_key($en, $ar)), 'in en.json but missing from ar.json');
        $this->assertSame([], array_values(array_diff_key($ar, $en)), 'in ar.json but missing from en.json');

        foreach ($ar as $key => $value) {
            $this->assertNotSame('', trim($value), "ar.json: {$key} is empty");
            $this->assertNotSame($key, $value, "ar.json: {$key} is untranslated");
        }
    }

    public function test_every_validation_rule_and_field_is_translated(): void
    {
        $en = require __DIR__.'/../../lang/en/validation.php';
        $ar = require __DIR__.'/../../lang/ar/validation.php';

        foreach (array_keys($en) as $rule) {
            $this->assertArrayHasKey($rule, $ar, "validation.{$rule} missing from ar");
        }
        foreach (['name', 'name_ar', 'price_usd', 'cost_usd', 'barcode', 'sku', 'quantity', 'username', 'password',
            'opening_cash', 'closing_cash', 'customer_id', 'supplier_id', 'credit_limit'] as $field) {
            $this->assertArrayHasKey($field, $ar['attributes'], "validation.attributes.{$field} missing from ar");
        }
    }

    public function test_the_required_arabic_labels_are_present(): void
    {
        $ar = self::lang('ar', 'pos');

        $this->assertSame('مفرق', $ar['tier.retail']);
        $this->assertSame('جملة', $ar['tier.wholesale']);
        $this->assertSame('VIP', $ar['tier.vip']);
        $this->assertSame('حسم', $ar['ui.discount']);
        $this->assertSame('حسم على الفاتورة', $ar['ui.cart_discount']);
    }

    public function test_the_glossary_is_used_consistently(): void
    {
        $roles = self::lang('ar', 'enums');

        // one word for the till operator everywhere, and the support account is named for what it is
        $this->assertSame('كاشير', $roles['role.cashier']);
        $this->assertSame('دعم Build Syntax', $roles['role.super_admin']);
        $this->assertSame('أمين المستودع', $roles['role.stock']);
        $this->assertSame('مدير النظام', $roles['role.admin']);
        $this->assertSame('كاشير', self::lang('ar', 'receipt')['cashier']);

        foreach (glob(__DIR__.'/../../lang/ar/*.php') as $file) {
            $this->assertStringNotContainsString('أمين صندوق', file_get_contents($file), basename($file).' still uses the old cashier term');
        }
    }
}
