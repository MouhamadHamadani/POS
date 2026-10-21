<?php

namespace Tests\Feature\Pos;

use App\Models\Product;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "The Complete button is enabled exactly when the server would take the payment." The payment modal
 * decides that in JavaScript (what is paid, what remains, whether card / Wish exceed the total, whether
 * cash is needed); this runs those live methods out of the Blade file via Node and posts the same
 * payments to the real endpoint, then requires the screen and the server to agree, and the change the
 * screen shows to be the change the server records. Skipped where Node is not installed.
 */
class PaymentModalParityTest extends TestCase
{
    use RefreshDatabase;

    private const RATE = 90000;

    private function nodeAvailable(): bool
    {
        exec('node --version', $out, $code);

        return $code === 0;
    }

    /** Amounts are on a cent grid (LBP in whole $0.10 notes) so the screen's 0.005 slack and the server's 0.001 cannot differ. */
    private function scenarios(): array
    {
        mt_srand(20261022);
        $cases = [];

        // Hand-picked edges first: exact, short, over, non-cash over, cash on a covered total, all-non-cash.
        foreach ([
            [10.00, ['amount_usd' => 10]],
            [10.00, ['amount_usd' => 9.99]],
            [10.00, ['amount_usd' => 20]],
            [10.00, ['amount_card' => 10]],
            [10.00, ['amount_card' => 10.01]],
            [10.00, ['amount_wish' => 4, 'amount_usd' => 6]],
            [10.00, ['amount_wish' => 4, 'amount_usd' => 10]],
            [10.00, ['amount_wish' => 10, 'amount_usd' => 5]],
            [10.00, ['amount_wish' => 6, 'amount_card' => 4]],
            [10.00, ['amount_wish' => 6, 'amount_card' => 5]],
            [10.00, ['amount_lbp' => 900000]],
            [10.00, ['amount_lbp' => 450000, 'amount_wish' => 5]],
            [10.00, ['amount_lbp' => 450000, 'amount_card' => 6]],
            [10.00, []],
        ] as [$total, $payment]) {
            $cases[] = ['total' => $total, 'payment' => $payment];
        }

        for ($i = 0; $i < 120; $i++) {
            $total = mt_rand(1, 20000) / 100;
            $payment = [];
            foreach (['amount_usd', 'amount_card', 'amount_wish'] as $field) {
                if (mt_rand(0, 2) > 0) {
                    $payment[$field] = mt_rand(0, 2) === 0 ? $total : mt_rand(0, (int) round($total * 100)) / 100;
                }
            }
            if (mt_rand(0, 3) === 0) {
                $payment['amount_lbp'] = mt_rand(0, 40) * 9000;
            }
            $cases[] = ['total' => $total, 'payment' => $payment];
        }

        // Totals with four decimals (per-line VAT): the till shows cents, so what the customer pays lands a
        // fraction of a cent either side of the stored total. This is where the half-cent slack matters.
        for ($i = 0; $i < 120; $i++) {
            $total = mt_rand(100, 200000) / 10000;
            $cents = round($total, 2);
            $wish = round($total * 0.4, 2);
            $card = round($total * 0.5, 2);
            $cases[] = ['total' => $total, 'payment' => match (mt_rand(0, 5)) {
                0 => ['amount_card' => $cents],
                1 => ['amount_wish' => $cents],
                2 => ['amount_card' => round($cents - 0.01, 2)],                              // a whole cent short
                3 => ['amount_wish' => $wish, 'amount_usd' => ceil(($total - $wish) * 100) / 100],
                4 => ['amount_card' => $card, 'amount_wish' => round($total - $card, 2)],
                default => ['amount_usd' => ceil($total * 100) / 100],
            }];
        }

        return $cases;
    }

    public function test_the_screen_and_the_server_agree_on_every_payment(): void
    {
        if (! $this->nodeAvailable()) {
            $this->markTestSkipped('Node is not installed.');
        }

        Setting::set('exchange_rate', self::RATE, 'currency', 'float');
        $cashier = User::factory()->create();
        Shift::create([
            'user_id' => $cashier->id, 'opened_at' => now(),
            'opening_cash_usd' => 0, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN,
        ]);
        $product = Product::factory()->create(['price_usd' => 1, 'cost_usd' => 0, 'stock_qty' => 100000, 'is_taxable' => false]);

        $scenarios = $this->scenarios();

        $process = proc_open(
            ['node', __DIR__.'/payment_modal_parity.cjs'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        fwrite($pipes[0], json_encode([
            'bladePath' => realpath(__DIR__.'/../../../resources/views/pos/sell.blade.php'),
            'exchangeRate' => self::RATE,
            'scenarios' => $scenarios,
        ]));
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        $this->assertSame(0, proc_close($process), $stderr);
        $screen = json_decode($stdout, true);
        $this->assertCount(count($scenarios), $screen);

        $accepted = 0;
        $mismatches = [];
        foreach ($scenarios as $i => $case) {
            $product->update(['price_usd' => $case['total']]);
            $response = $this->actingAs($cashier)->postJson('/pos/api/sales', [
                'cart' => [['product_id' => $product->id, 'qty' => 1]],
                'payment' => $case['payment'],
            ]);
            $serverTakesIt = $response->status() === 201;
            $accepted += $serverTakesIt ? 1 : 0;

            if ($serverTakesIt !== $screen[$i]['canComplete']) {
                $mismatches[] = ['case' => $case, 'server' => $response->status(), 'screen' => $screen[$i]];

                continue;
            }

            // When it goes through, the change on screen is the change that was recorded (all in USD by default).
            if ($serverTakesIt && $screen[$i]['change'] > 0.005) {
                $sale = Sale::latest('id')->first();
                $recorded = (float) $sale->change_usd + (float) $sale->change_lbp / self::RATE;
                if (abs($recorded - $screen[$i]['change']) > 0.0051) {
                    $mismatches[] = ['case' => $case, 'change_on_screen' => $screen[$i]['change'], 'change_recorded' => $recorded];
                }
            }
        }

        $this->assertSame([], $mismatches, 'the payment modal and SaleService disagree: '.json_encode(array_slice($mismatches, 0, 3)));
        $this->assertGreaterThan(20, $accepted, 'the scenarios must include payments the server takes');
        $this->assertLessThan(count($scenarios) - 20, $accepted, 'and payments it refuses');
    }
}
