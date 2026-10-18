<?php

namespace Tests\Feature\Stock;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** BUG-017: a manual stock adjustment is audited with the quantity before and after. */
class StockAdjustmentAuditTest extends TestCase
{
    use RefreshDatabase;

    private function adjust(Product $product, string $action, float $qty)
    {
        return $this->actingAs(User::factory()->stock()->create())
            ->post(route('products.adjust-stock', $product), ['action' => $action, 'qty' => $qty, 'reason' => 'Shelf recount']);
    }

    public function test_the_entry_records_old_and_new_quantity_and_the_reason(): void
    {
        $product = Product::factory()->create(['stock_qty' => 10, 'track_stock' => true]);

        $this->adjust($product, 'remove', 4)->assertSessionHasNoErrors();

        $log = AuditLog::where('action', 'stock_adjust')->sole();
        $this->assertSame(Product::class, $log->model_type);
        $this->assertSame($product->id, $log->model_id);
        $this->assertEquals(10, $log->old_values['stock_qty']);
        $this->assertEquals(6, $log->new_values['stock_qty']);
        $this->assertSame('remove', $log->new_values['action']);
        $this->assertSame('Shelf recount', $log->new_values['reason']);
    }

    public function test_a_set_records_the_replaced_quantity(): void
    {
        $product = Product::factory()->create(['stock_qty' => 10, 'track_stock' => true]);

        $this->adjust($product, 'set', 2)->assertSessionHasNoErrors();

        $log = AuditLog::where('action', 'stock_adjust')->sole();
        $this->assertEquals(10, $log->old_values['stock_qty']);
        $this->assertEquals(2, $log->new_values['stock_qty']);
    }

    public function test_a_refused_removal_leaves_no_entry(): void
    {
        $product = Product::factory()->create(['stock_qty' => 1, 'track_stock' => true]);

        $this->adjust($product, 'remove', 5)->assertSessionHasErrors('stock');

        $this->assertSame(0, AuditLog::where('action', 'stock_adjust')->count());
        $this->assertEquals(1, $product->fresh()->stock_qty);
    }
}
