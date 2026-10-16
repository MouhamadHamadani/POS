<?php

namespace Tests\Feature\BugHunt;

use App\Models\Category;
use App\Models\Product;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\BugHunt\Concerns\SubmitsRenderedForms;
use Tests\TestCase;

/**
 * BUG-044: deactivating the default VAT leaves it flagged "default", so every new product is saved tax-free.
 *
 * Since BUG-004 the Active box of a tax row really saves. SettingController::updateTax() leaves
 * `is_default` as submitted, so a rate can be is_active=0 AND is_default=1. ProductController::create()
 * offers only active taxes but takes `defaultTaxId` from `Tax::where('is_default', true)` without
 * checking is_active: no option matches, the browser submits "No tax", and the product is saved
 * with tax_id NULL. SaleService charges VAT only when the product has a tax, so it sells with no VAT.
 *
 * Both reasonable fixes pass these tests: refuse deactivating the default rate (it stays active and
 * default), or clear is_default when a rate is deactivated.
 */
class Bug044DeactivatedDefaultTaxMakesNewProductsTaxFreeTest extends TestCase
{
    use RefreshDatabase;
    use SubmitsRenderedForms;

    /** Settings > Tax: the owner un-ticks Active on the default rate and presses its Save. */
    private function untickActiveOnTheDefaultRate(Tax $tax, User $admin): void
    {
        $html = $this->actingAs($admin)->get(route('settings.index', ['tab' => 'tax']))->assertOk()->getContent();

        // Whether the save is accepted or refused is the fixer's call; the invariant below must hold either way.
        $this->submitRenderedForm($html, route('settings.tax.update', $tax), uncheck: ['is_active']);
    }

    public function test_no_deactivated_tax_is_left_flagged_as_the_default(): void
    {
        $vat = Tax::factory()->default()->create(['name' => 'VAT 11%', 'rate' => 0.11]);
        $admin = User::factory()->admin()->create();

        $this->untickActiveOnTheDefaultRate($vat, $admin);

        $this->assertSame(
            0,
            Tax::where('is_active', false)->where('is_default', true)->count(),
            'A deactivated rate is still flagged as the default tax (is_active=0, is_default=1).'
        );
    }

    public function test_a_new_product_made_on_the_rendered_form_gets_the_default_tax_if_there_is_one(): void
    {
        $vat = Tax::factory()->default()->create(['name' => 'VAT 11%', 'rate' => 0.11]);
        $category = Category::factory()->create();
        $admin = User::factory()->admin()->create();

        $this->untickActiveOnTheDefaultRate($vat, $admin);

        $html = $this->actingAs($admin)->get(route('products.create'))->assertOk()->getContent();
        $this->submitRenderedForm($html, route('products.store'), set: [
            'name' => 'Olive oil 1L', 'category_id' => $category->id, 'price_usd' => '10', 'cost_usd' => '6', 'sku' => 'OIL-1',
        ])->assertSessionHasNoErrors();

        $product = Product::where('sku', 'OIL-1')->firstOrFail();
        $default = Tax::where('is_default', true)->first(); // refused save: still VAT 11%; cleared flag: null

        $this->assertSame(
            $default?->id,
            $product->tax_id,
            $default
                ? 'The store has a default tax, but a product created on the form was saved with no tax.'
                : 'The product was saved with a tax although no tax is the default.'
        );
    }

    public function test_the_new_product_form_never_preselects_a_deactivated_tax(): void
    {
        // State an install can already be in (saved before any fix), so the form must cope with it too.
        Tax::factory()->default()->create(['name' => 'VAT 11%', 'rate' => 0.11, 'is_active' => false]);
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('products.create'))->assertOk();

        $preselected = $response->original->getData()['defaultTaxId'] ?? null;
        $this->assertTrue(
            $preselected === null || Tax::findOrFail($preselected)->is_active,
            'The new-product form preselects a deactivated tax, which is not even one of its options.'
        );
    }
}
