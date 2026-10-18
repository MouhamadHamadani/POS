<?php

namespace Tests\Feature\Forms;

use App\Models\Product;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\BugHunt\Concerns\SubmitsRenderedForms;
use Tests\TestCase;

/**
 * BUG-026: an un-ticked checkbox sends nothing, so after a validation error old() has no value for it
 * and the form fell back to the stored one. Each box carries a hidden 0 so the redisplay (and the save) match
 * what the user left. The customer, supplier and product-create cases are in tests/Feature/BugHunt.
 */
class CheckboxRedisplayTest extends TestCase
{
    use RefreshDatabase;
    use SubmitsRenderedForms;

    /** @return array<string,bool> checkbox name => ticked */
    private function checkboxes(string $html, array $names): array
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();

        $out = [];
        foreach ($names as $name) {
            $box = (new DOMXPath($dom))->query("//input[@type='checkbox'][@name='{$name}']")->item(0);
            $this->assertNotNull($box, "No checkbox named {$name} on the page.");
            $out[$name] = $box->hasAttribute('checked');
        }

        return $out;
    }

    public function test_user_form_keeps_active_unticked_after_a_validation_error(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->create(['is_active' => true]);
        $editUrl = route('users.edit', $staff);

        $html = $this->actingAs($admin)->get($editUrl)->assertOk()->getContent();
        $payload = $this->browserPayload($html, route('users.update', $staff), uncheck: ['is_active'], set: ['email' => 'not-an-email']);
        $page = $this->from($editUrl)->followingRedirects()->call('PUT', route('users.update', $staff), $payload);

        $page->assertSee('value="not-an-email"', false);
        $this->assertSame(['is_active' => false], $this->checkboxes($page->getContent(), ['is_active']));
    }

    public function test_product_form_keeps_every_unticked_box_unticked_after_a_validation_error(): void
    {
        Product::factory()->create(['sku' => 'TAKEN-2']);
        $product = Product::factory()->create(['force_lbp_price' => true]);
        $admin = User::factory()->admin()->create();
        $editUrl = route('products.edit', $product);
        $boxes = ['force_lbp_price', 'track_stock', 'is_taxable', 'allow_discount', 'is_active'];

        $html = $this->actingAs($admin)->get($editUrl)->assertOk()->getContent();
        $this->assertSame(array_fill_keys($boxes, true), $this->checkboxes($html, $boxes), 'Precondition: all boxes ticked.');

        $payload = $this->browserPayload($html, route('products.update', $product), uncheck: $boxes, set: ['sku' => 'TAKEN-2']);
        $page = $this->from($editUrl)->followingRedirects()->call('PUT', route('products.update', $product), $payload);

        $page->assertSee('value="TAKEN-2"', false);
        $this->assertSame(array_fill_keys($boxes, false), $this->checkboxes($page->getContent(), $boxes));
    }
}
