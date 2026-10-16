<?php

namespace Tests\Feature\BugHunt;

use App\Models\Setting;
use App\Models\User;
use App\Services\CurrencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\BugHunt\Concerns\SubmitsRenderedForms;
use Tests\TestCase;

/**
 * BUG-045: saving Settings > Currency silently resets an LBP rounding step the select does not list.
 *
 * The select offers only 100 / 500 / 1000 / 5000. CurrencyService treats a step of 0 as "no
 * rounding" (BUG-024 relies on it) and the server rule is `min:0`, so 0 (or any other value, from a
 * seed, an env file or a client profile) is a legal stored step. It matches no <option>, the browser
 * submits the first one (100), and changing only the exchange rate overwrites the step with 100.
 * Same class as BUG-014 (unit) and BUG-015 (role): a select that cannot show the stored value.
 */
class Bug045CurrencyTabResetsRoundingStepNotInSelectTest extends TestCase
{
    use RefreshDatabase;
    use SubmitsRenderedForms;

    /** @return array<string,array{0:float}> */
    public static function unlistedSteps(): array
    {
        return [
            'no rounding (0)' => [0.0],
            'odd step (250)' => [250.0],
        ];
    }

    #[DataProvider('unlistedSteps')]
    public function test_changing_only_the_exchange_rate_keeps_the_stored_rounding_step(float $step): void
    {
        Setting::set('exchange_rate', 90000, 'currency', 'float');
        Setting::set('lbp_rounding_step', $step, 'currency', 'float');
        $admin = User::factory()->admin()->create();

        $html = $this->actingAs($admin)->get(route('settings.index', ['tab' => 'currency']))->assertOk()->getContent();

        // The user types a new rate and touches nothing else.
        $this->submitRenderedForm($html, route('settings.update'), set: ['settings[exchange_rate]' => '91000'])
            ->assertSessionHasNoErrors();

        $this->assertEquals(91000, Setting::get('exchange_rate'), 'Precondition: the new exchange rate was saved.');
        $this->assertEquals(
            $step,
            app(CurrencyService::class)->getRoundingStep(),
            "The LBP rounding step was {$step} and became something else after saving only the exchange rate."
        );
    }
}
