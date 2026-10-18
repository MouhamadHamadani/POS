<?php

namespace Tests\Feature\BugHunt;

use App\Models\Setting;
use App\Models\Shift;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * BUG-039 (the part of BUG-024 that was deliberately left alone): the LBP cash-count inputs of the open-shift and
 * close-shift forms are hard-coded `step="1000"` and `required`, and the form is submitted natively, so the browser
 * refuses any count that is not a whole thousand. Settings > Currency offers LBP rounding steps of 100 and 500, and
 * CurrencyService::calculateChange() snaps LBP change to that step, so on such a store the drawer legitimately holds
 * (and the shift's expected_lbp shows) amounts like 1,234,500 that the cashier cannot type as the counted total.
 */
class Bug039ShiftLbpCashCountStepContradictsRoundingStepTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string,array{0:string,1:string}> */
    public static function fields(): array
    {
        return [
            'close shift counted LBP' => ['close', 'closing_cash_lbp'],
            'open shift opening LBP' => ['open', 'opening_cash_lbp'],
        ];
    }

    #[DataProvider('fields')]
    public function test_the_lbp_cash_count_accepts_multiples_of_the_stores_rounding_step(string $page, string $field): void
    {
        Setting::set('lbp_rounding_step', 500, 'currency', 'float');
        $user = User::factory()->create();
        if ($page === 'close') {
            Shift::create([
                'user_id' => $user->id, 'opened_at' => now(),
                'opening_cash_usd' => 0, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN,
            ]);
        }

        $html = $this->actingAs($user)->get($page === 'close' ? route('shifts.close') : route('shifts.open'))->assertOk()->getContent();

        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();
        $input = (new DOMXPath($dom))->query("//input[@name='{$field}']")->item(0);
        $this->assertNotNull($input, "No {$field} input.");

        $step = $input->getAttribute('step');
        if ($step === 'any') {
            $this->addToAssertionCount(1);

            return;
        }

        $this->assertGreaterThan(0, (float) $step);
        $this->assertEquals(0.0, fmod(500.0, (float) $step), "A count of 1,234,500 LBP is invalid for step=\"{$step}\" although the store rounds LBP change to 500.");
    }
}
