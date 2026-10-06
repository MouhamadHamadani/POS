<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** lang/en/pos.php and lang/ar/pos.php must define exactly the same keys. */
class PosLangParityTest extends TestCase
{
    /** @return array<string, string> flattened "a.b.c" => value */
    private function load(string $locale): array
    {
        $flat = [];
        $walk = function (array $node, string $prefix) use (&$walk, &$flat) {
            foreach ($node as $key => $value) {
                is_array($value) ? $walk($value, "{$prefix}{$key}.") : $flat["{$prefix}{$key}"] = $value;
            }
        };
        $walk(require __DIR__ . "/../../lang/{$locale}/pos.php", '');

        return $flat;
    }

    public function test_english_and_arabic_have_the_same_keys(): void
    {
        $en = $this->load('en');
        $ar = $this->load('ar');

        $this->assertSame([], array_values(array_diff_key($en, $ar)), 'in en but missing from ar');
        $this->assertSame([], array_values(array_diff_key($ar, $en)), 'in ar but missing from en');
    }

    public function test_placeholders_match_between_languages(): void
    {
        $placeholders = fn (string $s) => (function () use ($s) {
            preg_match_all('/:[a-z_]+/', $s, $m);
            sort($m[0]);

            return $m[0];
        })();

        foreach ($this->load('en') as $key => $english) {
            $this->assertSame(
                $placeholders($english),
                $placeholders($this->load('ar')[$key]),
                "placeholders differ for {$key}"
            );
        }
    }

    public function test_the_required_arabic_labels_are_present(): void
    {
        $ar = $this->load('ar');

        $this->assertSame('مفرق', $ar['tier.retail']);
        $this->assertSame('جملة', $ar['tier.wholesale']);
        $this->assertSame('VIP', $ar['tier.vip']);
        $this->assertSame('حسم', $ar['ui.discount']);
        $this->assertSame('حسم على الفاتورة', $ar['ui.cart_discount']);
    }
}
