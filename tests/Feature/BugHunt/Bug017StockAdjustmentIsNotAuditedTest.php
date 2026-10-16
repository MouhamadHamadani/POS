<?php

namespace Tests\Feature\BugHunt;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * BUG-017: the "Stock adjustment" form on the product page leaves no audit-log entry.
 *
 * CLAUDE.md: "AuditLog::record() is called on every mutating action (sales, stock adjustments,
 * settings changes, user management, etc.)". ProductController::adjustStock() writes an
 * inventory_adjustments row (the movement log) but never calls AuditLog::record(), so the
 * audit trail has no record of who added, removed or reset stock — including `set`/`count`,
 * which can erase a quantity outright.
 */
class Bug017StockAdjustmentIsNotAuditedTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string,array{0:string}> */
    public static function actions(): array
    {
        return ['add' => ['add'], 'remove' => ['remove'], 'set' => ['set'], 'count' => ['count']];
    }

    #[DataProvider('actions')]
    public function test_adjusting_stock_writes_an_audit_log_entry(string $action): void
    {
        $product = Product::factory()->create(['stock_qty' => 10, 'track_stock' => true]);
        $stockKeeper = User::factory()->stock()->create();

        $this->actingAs($stockKeeper)->post(route('products.adjust-stock', $product), [
            'action' => $action, 'qty' => 3, 'reason' => 'Shelf recount',
        ])->assertSessionHasNoErrors();

        $this->assertGreaterThanOrEqual(
            1,
            AuditLog::where('user_id', $stockKeeper->id)->count(),
            "A stock '{$action}' adjustment left no audit-log entry.",
        );
    }
}
