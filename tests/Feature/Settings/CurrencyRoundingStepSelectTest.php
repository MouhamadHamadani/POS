<?php

namespace Tests\Feature\Settings;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** BUG-045: the Currency tab select can show whatever LBP rounding step is stored. */
class CurrencyRoundingStepSelectTest extends TestCase
{
    use RefreshDatabase;

    private function selectOptions(float $step): array
    {
        Setting::set('lbp_rounding_step', $step, 'currency', 'float');
        $html = $this->actingAs(User::factory()->admin()->create())
            ->get(route('settings.index', ['tab' => 'currency']))->assertOk()->getContent();

        preg_match('/<select name="settings\[lbp_rounding_step\]".*?<\/select>/s', $html, $select);
        preg_match_all('/<option value="([^"]*)"( selected)?/', $select[0], $m, PREG_SET_ORDER);

        return array_map(fn ($o) => [$o[1], isset($o[2])], $m);
    }

    public function test_an_unlisted_stored_step_is_an_extra_selected_option(): void
    {
        $this->assertSame([['100', false], ['500', false], ['1000', false], ['5000', false], ['0', true]], $this->selectOptions(0));
        $this->assertSame([['100', false], ['500', false], ['1000', false], ['5000', false], ['250', true]], $this->selectOptions(250));
    }

    public function test_a_listed_stored_step_is_selected_with_no_extra_option(): void
    {
        $this->assertSame([['100', false], ['500', true], ['1000', false], ['5000', false]], $this->selectOptions(500));
    }
}
