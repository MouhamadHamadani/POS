<?php

namespace Tests\Feature\Shifts;

use App\Models\Setting;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BUG-039: the LBP cash-count inputs on open/close shift take any amount (step="any"): the drawer can hold
 * LBP that is not a whole thousand (500 rounding step, LBP expenses), so a browser step must not refuse it.
 */
class ShiftLbpCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_float_that_is_not_a_whole_thousand_opens_a_shift_and_the_matching_count_closes_it_with_no_variance(): void
    {
        Setting::set('lbp_rounding_step', 500, 'currency', 'float');
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('shifts.open'))->assertOk()
            ->assertSee('name="opening_cash_lbp" type="number" step="any"', false);
        $this->actingAs($user)->post('/shifts/open', ['opening_cash_usd' => 0, 'opening_cash_lbp' => 750500])
            ->assertSessionHasNoErrors();

        $this->actingAs($user)->get(route('shifts.close'))->assertOk()
            ->assertSee('name="closing_cash_lbp" type="number" step="any"', false);
        $this->actingAs($user)->post('/shifts/close', ['closing_cash_usd' => 0, 'closing_cash_lbp' => 750500])
            ->assertSessionHasNoErrors();

        $shift = Shift::firstOrFail();
        $this->assertEquals(750500, (float) $shift->opening_cash_lbp);
        $this->assertEquals(0.0, (float) $shift->variance_lbp);
    }
}
