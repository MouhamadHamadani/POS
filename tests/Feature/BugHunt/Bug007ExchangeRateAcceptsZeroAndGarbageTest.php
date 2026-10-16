<?php

namespace Tests\Feature\BugHunt;

use App\Models\Setting;
use App\Models\User;
use App\Services\CurrencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * BUG-007: Settings > Currency saves any exchange rate, including 0, negatives, blank and text.
 *
 * SettingController::update() validates only `group` and `settings` (an array), then
 * stores every known key as `(string) $value`. The form itself allows 0 (`min="0"`).
 * With a rate of 0, CurrencyService::lbpToUsd() returns 0, so every LBP note a customer
 * hands over is counted as $0 tendered, and usdToLbp() prices everything at 0 LBP.
 */
class Bug007ExchangeRateAcceptsZeroAndGarbageTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string,array{0:mixed}> */
    public static function badRates(): array
    {
        return [
            'zero' => ['0'],
            'negative' => ['-90000'],
            'blank' => [''],
            'text' => ['abc'],
        ];
    }

    #[DataProvider('badRates')]
    public function test_an_unusable_exchange_rate_is_not_saved(mixed $bad): void
    {
        Setting::set('exchange_rate', 90000, 'currency', 'float');
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('settings.update'), [
            'group' => 'currency',
            'settings' => ['exchange_rate' => $bad, 'lbp_rounding_step' => 1000],
        ]);

        $rate = app(CurrencyService::class)->getRate();
        $this->assertGreaterThan(0, $rate, "Exchange rate became {$rate} after saving '{$bad}'.");
        $this->assertEquals(90000, $rate);
    }
}
