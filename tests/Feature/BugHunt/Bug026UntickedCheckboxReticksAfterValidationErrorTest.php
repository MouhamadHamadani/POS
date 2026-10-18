<?php

namespace Tests\Feature\BugHunt;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\BugHunt\Concerns\SubmitsRenderedForms;
use Tests\TestCase;

/**
 * BUG-026: after a validation error, a checkbox the user had just un-ticked comes back ticked.
 *
 * Laravel flashes only the fields the browser sent, and an un-ticked checkbox sends nothing.
 * The views repopulate with `old('tax_exempt', $customer->tax_exempt)`, so with no old value for
 * the box they fall back to the stored value: the form is redisplayed with the box ticked again,
 * next to the error message about a different field. Fix the email and press Save, and the
 * customer is saved as tax-exempt after all (or, for "Active", left active) with no hint.
 * Customer (tax_exempt, is_active), supplier and user (is_active) forms all use this pattern.
 */
class Bug026UntickedCheckboxReticksAfterValidationErrorTest extends TestCase
{
    use RefreshDatabase;
    use SubmitsRenderedForms;

    private function checkboxIsChecked(string $html, string $name): bool
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();
        $box = (new DOMXPath($dom))->query("//input[@type='checkbox'][@name='{$name}']")->item(0);
        $this->assertNotNull($box, "No checkbox named {$name} on the page.");

        return $box->hasAttribute('checked');
    }

    public function test_customer_form_keeps_tax_exempt_unticked_after_a_validation_error(): void
    {
        $customer = Customer::create(['name' => 'Rami', 'customer_group' => 'retail', 'is_active' => true, 'credit_limit' => 0, 'tax_exempt' => true]);
        $admin = User::factory()->admin()->create();
        $editUrl = route('customers.edit', $customer);

        $html = $this->actingAs($admin)->get($editUrl)->assertOk()->getContent();
        $this->assertTrue($this->checkboxIsChecked($html, 'tax_exempt'), 'Precondition: the stored customer is tax exempt.');

        $payload = $this->browserPayload($html, route('customers.update', $customer), uncheck: ['tax_exempt'], set: ['email' => 'not-an-email']);
        $page = $this->from($editUrl)->followingRedirects()->call('PUT', route('customers.update', $customer), $payload);

        $page->assertSee('value="not-an-email"', false); // redisplayed from the flashed old input, with the validation error
        $this->assertFalse($this->checkboxIsChecked($page->getContent(), 'tax_exempt'), 'The tax-exempt box the user un-ticked is ticked again.');
    }

    public function test_supplier_form_keeps_active_unticked_after_a_validation_error(): void
    {
        $supplier = Supplier::create(['name' => 'Acme Foods', 'payment_terms' => 'NET30', 'is_active' => true]);
        $admin = User::factory()->admin()->create();
        $editUrl = route('suppliers.edit', $supplier);

        $html = $this->actingAs($admin)->get($editUrl)->assertOk()->getContent();
        $payload = $this->browserPayload($html, route('suppliers.update', $supplier), uncheck: ['is_active'], set: ['email' => 'not-an-email']);
        $page = $this->from($editUrl)->followingRedirects()->call('PUT', route('suppliers.update', $supplier), $payload);

        $page->assertSee('value="not-an-email"', false); // redisplayed from the flashed old input
        $this->assertFalse($this->checkboxIsChecked($page->getContent(), 'is_active'), 'The Active box the user un-ticked is ticked again.');
    }

    public function test_product_form_keeps_track_stock_unticked_after_a_validation_error(): void
    {
        // A service item: the user un-ticks "Track stock", but types a SKU that is already taken.
        Product::factory()->create(['sku' => 'TAKEN-1']);
        $category = Category::factory()->create();
        $admin = User::factory()->admin()->create();
        $createUrl = route('products.create');

        $html = $this->actingAs($admin)->get($createUrl)->assertOk()->getContent();
        $payload = $this->browserPayload($html, route('products.store'), uncheck: ['track_stock'], set: [
            'name' => 'Delivery fee', 'category_id' => $category->id, 'price_usd' => '3', 'cost_usd' => '0', 'sku' => 'TAKEN-1',
        ]);
        $page = $this->from($createUrl)->followingRedirects()->call('POST', route('products.store'), $payload);

        $page->assertSee('value="TAKEN-1"', false); // redisplayed from the flashed old input, with the SKU error
        $this->assertFalse($this->checkboxIsChecked($page->getContent(), 'track_stock'), 'The Track stock box the user un-ticked is ticked again.');
    }
}
