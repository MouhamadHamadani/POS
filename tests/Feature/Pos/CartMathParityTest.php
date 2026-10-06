<?php

namespace Tests\Feature\Pos;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Tax;
use App\Services\SaleService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * "On-screen total equals the stored sales.total_usd." The sell screen re-does
 * SaleService::totalsFromCart in JavaScript so the cashier sees the real total
 * before paying; this runs that JavaScript (the live function out of the Blade
 * file, via Node) and the PHP service over the same seeded carts and requires
 * every amount to agree. Skipped where Node is not installed.
 */
class CartMathParityTest extends TestCase
{
    private function nodeAvailable(): bool
    {
        exec('node --version', $out, $code);

        return $code === 0;
    }

    /** @return array{0: array, 1: array} [PHP expectation, scenario for JS] */
    private function scenario(int $n): array
    {
        $tiers = ['retail', 'wholesale', 'vip'];
        $tier = $tiers[mt_rand(0, 2)];
        $exempt = mt_rand(1, 6) === 1;
        $customer = (new Customer())->forceFill(['tax_exempt' => $exempt, 'customer_group' => $tier]);

        $taxChoices = [null, [0.11, false], [0.11, true], [0.05, false], [0.18, true]];
        $cart = [];
        $jsCart = [];
        for ($i = 0, $lines = mt_rand(1, 6); $i < $lines; $i++) {
            $retail = mt_rand(5, 20000) / 100;
            $product = (new Product())->forceFill([
                'id' => $n * 10 + $i, 'name' => "P{$n}-{$i}",
                'price_usd' => $retail,
                'wholesale_price_usd' => mt_rand(0, 3) ? round($retail * 0.8, 4) : null,
                'vip_price_usd' => mt_rand(0, 3) ? round($retail * 0.7, 4) : null,
                'allow_discount' => mt_rand(1, 7) !== 1,
                'is_taxable' => mt_rand(1, 8) !== 1,
            ]);
            $taxChoice = $taxChoices[mt_rand(0, 4)];
            if ($taxChoice) {
                $product->setRelation('tax', (new Tax())->forceFill(['rate' => $taxChoice[0], 'is_inclusive' => $taxChoice[1]]));
            } else {
                $product->setRelation('tax', null);
            }

            $qty = [1, 2, 3, 0.5, 1.25, 7, 0.3333, 12][mt_rand(0, 7)];
            $row = ['product' => $product, 'product_id' => $product->id, 'qty' => $qty];
            $kind = mt_rand(0, 3);
            if ($kind === 1) {
                $row['discount_pct'] = mt_rand(1, 4000) / 100;
            } elseif ($kind === 2) {
                $row['discount_amount'] = round($retail * $qty * mt_rand(1, 50) / 100, 2);
            }
            $cart[] = $row;

            $prices = [
                'retail' => $retail,
                'wholesale' => (float) ($product->wholesale_price_usd ?? $retail),
                'vip' => (float) ($product->vip_price_usd ?? $retail),
            ];
            $jsCart[] = [
                'name' => $product->name, 'qty' => $qty,
                'unit_price' => $prices[$tier],
                'discount_pct' => $row['discount_pct'] ?? 0,
                'discount_amount' => $row['discount_amount'] ?? 0,
                'allow_discount' => (bool) $product->allow_discount,
                'is_taxable' => (bool) $product->is_taxable,
                'tax_rate' => $taxChoice ? $taxChoice[0] : 0,
                'tax_inclusive' => $taxChoice ? $taxChoice[1] : false,
            ];
        }

        $cartDiscount = match (mt_rand(0, 3)) {
            1 => ['type' => 'pct', 'value' => mt_rand(1, 3500) / 100],
            2 => ['type' => 'amount', 'value' => mt_rand(1, 4000) / 100],
            default => ['type' => 'pct', 'value' => 0],
        };

        return [[$cart, $customer, $tier, $cartDiscount], ['cart' => $jsCart, 'cartDiscount' => $cartDiscount, 'customer' => ['tax_exempt' => $exempt]]];
    }

    public function test_the_browser_arithmetic_matches_the_server_to_the_stored_decimal(): void
    {
        if (!$this->nodeAvailable()) {
            $this->markTestSkipped('Node is not installed.');
        }

        mt_srand(20261006);
        $service = app(SaleService::class);
        $expected = [];
        $scenarios = [];

        for ($n = 1; count($expected) < 300 && $n < 2000; $n++) {
            [[$cart, $customer, $tier, $cd], $js] = $this->scenario($n);
            try {
                $totals = $service->totalsFromCart($cart, $customer, $tier, [
                    'pct' => $cd['type'] === 'pct' ? $cd['value'] : 0,
                    'amount' => $cd['type'] === 'amount' ? $cd['value'] : 0,
                ]);
            } catch (ValidationException) {
                continue; // an invalid cart (discount bigger than the line): the screen blocks these, nothing to compare
            }
            $expected[] = $totals;
            $scenarios[] = $js;
        }
        $this->assertCount(300, $expected, 'could not generate enough valid carts');

        $process = proc_open(
            ['node', __DIR__ . '/cart_math_parity.cjs'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        fwrite($pipes[0], json_encode([
            'bladePath' => realpath(__DIR__ . '/../../../resources/views/pos/sell.blade.php'),
            'scenarios' => $scenarios,
        ]));
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        $this->assertSame(0, proc_close($process), $stderr);

        $actual = json_decode($stdout, true);
        $this->assertCount(300, $actual);

        $mismatches = [];
        foreach ($expected as $i => $php) {
            $js = $actual[$i];
            $pairs = [
                'subtotal' => [$php['subtotal'], $js['subtotal']],
                'discount' => [$php['discount'], $js['discount']],
                'cart discount' => [$php['cart_discount'], $js['cartDisc']],
                'tax' => [$php['tax'], $js['tax']],
                'total' => [$php['total'], $js['total']],
            ];
            foreach ($php['lines'] as $l => $line) {
                $pairs["line {$l} discount"] = [$line['discount_amount'], $js['lines'][$l]['discount']];
                $pairs["line {$l} tax"] = [$line['tax_amount'], $js['lines'][$l]['tax']];
                $pairs["line {$l} total"] = [$line['line_total'], $js['lines'][$l]['total']];
            }
            foreach ($pairs as $what => [$server, $browser]) {
                if (abs($server - $browser) > 1e-9) {
                    $mismatches[] = "scenario {$i} {$what}: server {$server} vs screen {$browser}";
                }
            }
        }

        $this->assertSame([], array_slice($mismatches, 0, 10), count($mismatches) . ' amounts differ');
    }
}
