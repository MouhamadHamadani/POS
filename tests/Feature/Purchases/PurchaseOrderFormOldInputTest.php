<?php

namespace Tests\Feature\Purchases;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** BUG-025: a PO that fails validation comes back with the lines as typed (tax as a percentage). */
class PurchaseOrderFormOldInputTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<int,array<string,mixed>> the `lines` the Alpine form is seeded with */
    private function lines(string $html): array
    {
        $this->assertSame(1, preg_match('/lines: (\[.*\]),\s*picker/s', $html, $m), 'No lines array in the form.');

        return json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_a_failed_create_keeps_each_line_as_typed(): void
    {
        $supplier = Supplier::create(['name' => 'Acme Foods', 'payment_terms' => 'NET30', 'is_active' => true]);
        $a = Product::factory()->create(['name' => 'Alpha']);
        $b = Product::factory()->create(['name' => 'Beta']);

        $html = $this->actingAs(User::factory()->manager()->create())
            ->from(route('purchases.create'))->followingRedirects()
            ->post(route('purchases.store'), [
                'supplier_id' => $supplier->id,
                'items' => [
                    ['id' => '', 'product_id' => $a->id, 'qty_ordered' => '5', 'cost_usd' => '2.5', 'tax_rate' => '0.11'],
                    ['id' => '', 'product_id' => $b->id, 'qty_ordered' => '3', 'cost_usd' => '', 'tax_rate' => ''],
                ],
            ])->getContent();

        $lines = $this->lines($html);
        $this->assertCount(2, $lines);
        $this->assertSame([null, $a->id, 'Alpha', '5', '2.5', 11], array_values($lines[0]));
        $this->assertSame([null, $b->id, 'Beta', '3', '', ''], array_values($lines[1]));
        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_a_failed_edit_shows_the_typed_lines_not_the_stored_ones(): void
    {
        $manager = User::factory()->manager()->create();
        $supplier = Supplier::create(['name' => 'Acme Foods', 'payment_terms' => 'NET30', 'is_active' => true]);
        $product = Product::factory()->create(['name' => 'Alpha']);
        $this->actingAs($manager)->post(route('purchases.store'), [
            'supplier_id' => $supplier->id, 'status' => 'draft',
            'items' => [['product_id' => $product->id, 'qty_ordered' => 10, 'cost_usd' => 5, 'tax_rate' => 0.11]],
        ])->assertSessionHasNoErrors();
        $po = PurchaseOrder::firstOrFail();
        $stored = PurchaseOrderItem::firstOrFail();

        $html = $this->actingAs($manager)->from(route('purchases.edit', $po))->followingRedirects()
            ->put(route('purchases.update', $po), [
                'supplier_id' => $supplier->id,
                'items' => [['id' => $stored->id, 'product_id' => $product->id, 'qty_ordered' => '7', 'cost_usd' => '', 'tax_rate' => '0.05']],
            ])->getContent();

        $lines = $this->lines($html);
        $this->assertCount(1, $lines);
        $this->assertSame($stored->id, $lines[0]['id']);
        $this->assertSame('7', $lines[0]['qty_ordered']);
        $this->assertSame('', $lines[0]['cost_usd']);
        $this->assertSame(5, $lines[0]['tax_pct']);
    }

    public function test_the_first_render_still_seeds_from_the_model(): void
    {
        $manager = User::factory()->manager()->create();
        $this->assertSame([], $this->lines($this->actingAs($manager)->get(route('purchases.create'))->getContent()));
    }
}
