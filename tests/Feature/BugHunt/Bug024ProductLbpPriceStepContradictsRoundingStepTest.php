<?php

namespace Tests\Feature\BugHunt;

use App\Models\Setting;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BUG-024: the product form's "LBP price" input is hard-coded to step="1000", whatever LBP rounding step the store chose.
 *
 * Settings > Currency offers rounding steps of 100, 500, 1000 and 5000, and every LBP amount on
 * the till is rounded to that step. A shop on a 500 (or 100) step prices items at 1,500 or 2,500 LBP,
 * but the browser refuses to submit the product form with such a value ("the two nearest valid values
 * are 1000 and 2000"), so the explicit LBP price (and "force LBP price") cannot be entered.
 */
class Bug024ProductLbpPriceStepContradictsRoundingStepTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_lbp_price_input_accepts_multiples_of_the_stores_rounding_step(): void
    {
        Setting::set('lbp_rounding_step', 500, 'currency', 'float');
        $admin = User::factory()->admin()->create();

        $html = $this->actingAs($admin)->get(route('products.create'))->assertOk()->getContent();

        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();
        $input = (new DOMXPath($dom))->query("//input[@name='price_lbp']")->item(0);
        $this->assertNotNull($input, 'No price_lbp input on the product form.');

        $step = $input->getAttribute('step');
        if ($step === 'any') {
            $this->addToAssertionCount(1);

            return;
        }

        $this->assertGreaterThan(0, (float) $step);
        $this->assertEquals(0.0, fmod(500.0, (float) $step), "A 2,500 LBP price is invalid for step=\"{$step}\" although the store rounds LBP to 500.");
    }
}
