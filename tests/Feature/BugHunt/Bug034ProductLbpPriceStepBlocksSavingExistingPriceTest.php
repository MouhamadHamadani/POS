<?php

namespace Tests\Feature\BugHunt;

use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * BUG-034 (follow-on to BUG-024): the product form's LBP price input uses the store's LBP rounding step as its
 * HTML `step`. A browser refuses to submit a number input whose value is not a multiple of its step, and the
 * form has no `novalidate`. The input sits on the hidden Pricing tab, so the product edit form simply cannot be
 * saved (Chrome: "An invalid form control is not focusable") for ANY edit of a product whose stored LBP price
 * is not a multiple of the step: after the store moves to a 5,000 step, a 12,000 LBP price becomes unsaveable.
 * `price_lbp` is an informational/forced price that nothing in the sale code reads, so the step must not be able
 * to lock a stored value out (keep the finest step or `any`; if it is validated at all, do it server-side).
 */
class Bug034ProductLbpPriceStepBlocksSavingExistingPriceTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string,array{0:int,1:int}> rounding step, stored price */
    public static function cases(): array
    {
        return [
            'step raised to 5000, price 12000 saved earlier' => [5000, 12000],
            'default 1000 step, price 13500 from a bulk import' => [1000, 13500],
        ];
    }

    #[DataProvider('cases')]
    public function test_the_edit_form_stays_saveable_when_the_stored_lbp_price_is_not_a_multiple_of_the_rounding_step(int $step, int $price): void
    {
        Setting::set('lbp_rounding_step', $step, 'currency', 'float');
        $product = Product::factory()->create(['price_lbp' => $price]);
        $admin = User::factory()->admin()->create();

        $html = $this->actingAs($admin)->get(route('products.edit', $product))->assertOk()->getContent();

        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();
        $input = (new DOMXPath($dom))->query("//input[@name='price_lbp']")->item(0);
        $this->assertNotNull($input, 'No price_lbp input on the product form.');
        $this->assertEquals($price, (float) $input->getAttribute('value'), 'Precondition: the form shows the stored LBP price.');

        $stepAttr = $input->getAttribute('step');
        if ($stepAttr === 'any') {
            $this->addToAssertionCount(1);

            return;
        }

        $this->assertGreaterThan(0, (float) $stepAttr);
        $this->assertEquals(
            0.0,
            fmod((float) $price, (float) $stepAttr),
            "The stored LBP price {$price} is invalid for step=\"{$stepAttr}\": the browser blocks the Save button for every edit of this product.",
        );
    }
}
