<?php

namespace Tests\Feature\Stock;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\InventoryAdjustment;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\ReturnTransaction;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\Supplier;
use App\Models\Tax;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\StockMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * The stock-movements screen over inventory_adjustments, and the opening-stock
 * rows that close the gap where initial stock used to appear with no history.
 */
class StockMovementsTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): User
    {
        return User::factory()->manager()->create();
    }

    private function rows(array $input = []): array
    {
        $f = app(StockMovementService::class)->filters($input);

        return app(StockMovementService::class)->paginate($f)->getCollection()->keyBy('type')->all();
    }

    /** One of each source: a sale, a PO receipt, a return and a manual write-off. */
    public function test_each_kind_of_movement_shows_with_the_right_direction_and_reference(): void
    {
        $manager = $this->manager();
        $vat = Tax::create(['name' => 'Lebanese VAT', 'rate' => 0.11, 'is_inclusive' => false, 'is_default' => true, 'is_active' => true]);
        $rice = Product::factory()->create(['name' => 'Rice 1kg', 'stock_qty' => 100, 'price_usd' => 10, 'tax_id' => $vat->id, 'is_taxable' => true]);
        $sugar = Product::factory()->create(['name' => 'Sugar 1kg', 'stock_qty' => 8, 'is_taxable' => false]);

        Shift::create(['user_id' => $manager->id, 'opened_at' => now(), 'opening_cash_usd' => 0, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN]);

        // sale: -5 rice
        $saleId = $this->actingAs($manager)->postJson('/pos/api/sales', [
            'cart' => [['product_id' => $rice->id, 'qty' => 5]],
            'payment' => ['method' => 'cash_usd', 'amount_usd' => 60.00],
        ])->assertCreated()->json('sale.id');
        $sale = Sale::findOrFail($saleId);

        // PO receive: +20 sugar
        $supplier = Supplier::create(['name' => 'Wholesaler', 'is_active' => true, 'balance' => 0]);
        $po = PurchaseOrder::create([
            'po_number' => 'PO-0001', 'supplier_id' => $supplier->id, 'user_id' => $manager->id,
            'status' => 'sent', 'subtotal_usd' => 40, 'total_usd' => 40,
        ]);
        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id, 'product_id' => $sugar->id, 'product_name' => $sugar->name,
            'qty_ordered' => 20, 'qty_received' => 0, 'cost_usd' => 2, 'line_total_usd' => 40,
        ]);
        $this->actingAs($manager)->post("/purchase-orders/{$po->id}/receive", [
            'receipts' => [['item_id' => $item->id, 'qty' => 20]],
        ])->assertRedirect();

        // return: +2 rice back
        $return = ReturnTransaction::create([
            'return_number' => 'RTN-0001', 'sale_id' => $sale->id, 'user_id' => $manager->id,
            'reason' => 'defective', 'refund_method' => 'cash_usd', 'refund_amount_usd' => 20, 'status' => 'approved',
        ]);
        app(InventoryService::class)->restockFromReturn($rice, 2, $manager->id, $return->id);

        // manual: -3 rice
        $this->actingAs($manager)->post("/products/{$rice->id}/adjust-stock", [
            'action' => 'remove', 'qty' => 3, 'reason' => 'Damaged sack',
        ])->assertRedirect();

        $rows = $this->rows();

        $this->assertEqualsWithDelta(-5, (float) $rows['sale']->qty_change, 0.0001);
        $this->assertSame($sale->receipt_number, $rows['sale']->ref_label);
        $this->assertSame(route('receipts.print', $sale->id), $rows['sale']->ref_url);

        $this->assertEqualsWithDelta(20, (float) $rows['po_receive']->qty_change, 0.0001);
        $this->assertSame('PO-0001', $rows['po_receive']->ref_label);
        $this->assertSame(route('purchases.show', $po->id), $rows['po_receive']->ref_url);

        $this->assertEqualsWithDelta(2, (float) $rows['return']->qty_change, 0.0001);
        $this->assertSame('RTN-0001', $rows['return']->ref_label);
        $this->assertSame(route('returns.show', $return->id), $rows['return']->ref_url);

        $this->assertEqualsWithDelta(-3, (float) $rows['remove']->qty_change, 0.0001);
        $this->assertSame('manual', $rows['remove']->ref_label);
        $this->assertNull($rows['remove']->ref_url);
        $this->assertEqualsWithDelta(94, (float) $rows['remove']->qty_after, 0.0001); // 100 -5 +2 -3

        // And the page renders them: label, balance and links.
        $this->actingAs($manager)->get('/products/movements')
            ->assertOk()
            ->assertSee('Stock Movements')
            ->assertSee($sale->receipt_number)
            ->assertSee('PO-0001')
            ->assertSee('RTN-0001')
            ->assertSee('Damaged sack')
            ->assertSee('Goods received')
            ->assertSee(route('purchases.show', $po->id), false);
    }

    public function test_summary_bar_totals_in_out_and_net_for_the_filtered_range(): void
    {
        $manager = $this->manager();
        $p = Product::factory()->create(['stock_qty' => 10]);
        $inv = app(InventoryService::class);
        $inv->adjust($p, 6, InventoryAdjustment::TYPE_ADD, $manager->id, reason: 'delivery');
        $inv->adjust($p, -2.5, InventoryAdjustment::TYPE_REMOVE, $manager->id, reason: 'breakage');
        $inv->adjust($p, -1, InventoryAdjustment::TYPE_REMOVE, $manager->id, reason: 'breakage');

        $service = app(StockMovementService::class);
        $this->assertSame(['in' => 6.0, 'out' => 3.5, 'net' => 2.5], $service->summary($service->filters([])));
        $this->assertSame(['in' => 0.0, 'out' => 3.5, 'net' => -3.5], $service->summary($service->filters(['direction' => 'out'])));

        $this->actingAs($manager)->get('/products/movements')->assertOk()->assertSee('Total in')->assertSee('+6')->assertSee('−3.5');
    }

    public function test_filters_narrow_by_date_product_category_direction_and_type(): void
    {
        $manager = $this->manager();
        $drinks = Category::factory()->create(['name' => 'Drinks']);
        $snacks = Category::factory()->create(['name' => 'Snacks']);
        $cola = Product::factory()->create(['name' => 'Cola', 'name_ar' => 'كولا', 'barcode' => '111', 'sku' => 'CL-1', 'category_id' => $drinks->id, 'stock_qty' => 0]);
        $chips = Product::factory()->create(['name' => 'Chips', 'category_id' => $snacks->id, 'stock_qty' => 0]);
        $inv = app(InventoryService::class);

        $inv->adjust($cola, 10, InventoryAdjustment::TYPE_ADD, $manager->id, reason: 'a');
        $inv->adjust($cola, -4, InventoryAdjustment::TYPE_REMOVE, $manager->id, reason: 'b');
        $inv->adjust($chips, 7, InventoryAdjustment::TYPE_ADD, $manager->id, reason: 'c');
        // an old one, outside the default current-month window
        $inv->adjust($chips, 1, InventoryAdjustment::TYPE_ADD, $manager->id, reason: 'old');
        InventoryAdjustment::where('reason', 'old')->update(['created_at' => now()->subMonths(3)]);

        $svc = app(StockMovementService::class);
        $count = fn (array $in) => $svc->query($svc->filters($in))->count();

        $this->assertSame(3, $count([]));                                  // old row excluded by the month default
        $this->assertSame(4, $count(['from' => now()->subYear()->format('Y-m-d')]));
        $this->assertSame(2, $count(['product' => 'كولا']));               // Arabic name
        $this->assertSame(2, $count(['product' => 'CL-1']));               // SKU
        $this->assertSame(2, $count(['product' => '111']));                // barcode
        $this->assertSame(1, $count(['category_id' => $snacks->id]));
        $this->assertSame(2, $count(['direction' => 'in']));
        $this->assertSame(1, $count(['direction' => 'out']));
        $this->assertSame(1, $count(['type' => InventoryAdjustment::TYPE_REMOVE]));
        $this->assertSame(2, $count(['product_id' => $cola->id]));

        $this->actingAs($manager)->get('/products/movements?direction=sideways')->assertSessionHasErrors('direction');
    }

    public function test_creating_a_product_with_initial_stock_logs_an_opening_row(): void
    {
        $admin = User::factory()->admin()->create();
        $cat = Category::factory()->create();

        $this->actingAs($admin)->post('/products', [
            'name' => 'Opened', 'category_id' => $cat->id, 'price_usd' => 3, 'stock_qty' => 12,
        ])->assertSessionHasNoErrors();

        $product = Product::where('name', 'Opened')->sole();
        $this->assertEqualsWithDelta(12, (float) $product->stock_qty, 0.0001);

        $row = InventoryAdjustment::where('product_id', $product->id)->sole();
        $this->assertSame(InventoryAdjustment::TYPE_OPENING, $row->type);
        $this->assertEqualsWithDelta(0, (float) $row->qty_before, 0.0001);
        $this->assertEqualsWithDelta(12, (float) $row->qty_change, 0.0001);
        $this->assertEqualsWithDelta(12, (float) $row->qty_after, 0.0001);
        $this->assertSame($admin->id, $row->user_id);

        // The audit entry still records the stock the product started with.
        $this->assertEqualsWithDelta(12, (float) AuditLog::where('action', 'create')->where('model_id', $product->id)->sole()->new_values['stock_qty'], 0.0001);
    }

    public function test_a_product_created_with_no_stock_logs_nothing(): void
    {
        $cat = Category::factory()->create();

        $this->actingAs(User::factory()->admin()->create())->post('/products', [
            'name' => 'Empty', 'category_id' => $cat->id, 'price_usd' => 3, 'stock_qty' => '',
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, InventoryAdjustment::count());
    }

    public function test_editing_a_product_never_writes_stock_around_the_log(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create(['stock_qty' => 10]);

        // The form posts its read-only stock field back; a stale value must not win.
        $this->actingAs($admin)->put("/products/{$product->id}", [
            'name' => 'Renamed', 'category_id' => $product->category_id, 'price_usd' => 5, 'stock_qty' => 999,
        ])->assertSessionHasNoErrors();

        $this->assertSame('Renamed', $product->fresh()->name);
        $this->assertEqualsWithDelta(10, (float) $product->fresh()->stock_qty, 0.0001);
    }

    public function test_bulk_import_with_stock_logs_an_opening_row(): void
    {
        Setting::set('bulk_upload_enabled_admin', '1', 'permissions', 'bool');
        Category::factory()->create(['name' => 'Beverages']);
        $admin = User::factory()->admin()->create();

        $path = tempnam(sys_get_temp_dir(), 'import').'.csv';
        file_put_contents($path, "name,category,price_usd,stock_qty\nStocked,Beverages,2.00,24\nUnstocked,Beverages,2.00,\n");

        $this->actingAs($admin)->post('/products/import/preview', ['file' => new UploadedFile($path, 'p.csv', 'text/csv', null, true)])->assertOk();
        $this->actingAs($admin)->post('/products/import');

        $stocked = Product::where('name', 'Stocked')->sole();
        $row = InventoryAdjustment::where('product_id', $stocked->id)->sole();
        $this->assertSame(InventoryAdjustment::TYPE_OPENING, $row->type);
        $this->assertEqualsWithDelta(24, (float) $stocked->stock_qty, 0.0001);
        $this->assertEqualsWithDelta(24, (float) $row->qty_after, 0.0001);

        $this->assertSame(0, InventoryAdjustment::where('product_id', Product::where('name', 'Unstocked')->value('id'))->count());
    }

    public function test_the_product_edit_page_has_a_movements_tab_for_that_product_only(): void
    {
        $manager = $this->manager();
        $a = Product::factory()->create(['name' => 'Alpha Item', 'stock_qty' => 0]);
        $b = Product::factory()->create(['name' => 'Beta Item', 'stock_qty' => 0]);
        app(InventoryService::class)->adjust($a, 5, InventoryAdjustment::TYPE_ADD, $manager->id, reason: 'alpha delivery');
        app(InventoryService::class)->adjust($b, 9, InventoryAdjustment::TYPE_ADD, $manager->id, reason: 'beta delivery');

        $this->actingAs($manager)->get("/products/{$a->id}/edit")
            ->assertOk()
            ->assertSee('Movements')
            ->assertSee('alpha delivery')
            ->assertDontSee('beta delivery')
            ->assertSee(e(route('products.movements', ['product_id' => $a->id, 'from' => '2000-01-01', 'to' => now()->format('Y-m-d')])), false);

        $this->actingAs($manager)->get("/products/movements?product_id={$a->id}")
            ->assertOk()->assertSee('alpha delivery')->assertDontSee('beta delivery');
    }

    public function test_only_admin_manager_and_stock_roles_can_open_it(): void
    {
        foreach (['admin', 'manager', 'stock'] as $role) {
            $this->actingAs(User::factory()->{$role}()->create())->get('/products/movements')->assertOk();
        }

        $this->actingAs(User::factory()->create())->get('/products/movements')->assertForbidden();
        $this->actingAs(User::factory()->create())->get('/products/movements/export')->assertForbidden();
    }

    public function test_csv_export_follows_the_filters_and_is_blocked_in_a_demo_build(): void
    {
        $manager = $this->manager();
        $p = Product::factory()->create(['name' => 'Exported', 'stock_qty' => 0]);
        app(InventoryService::class)->adjust($p, 4, InventoryAdjustment::TYPE_ADD, $manager->id, reason: 'in');
        app(InventoryService::class)->adjust($p, -1, InventoryAdjustment::TYPE_REMOVE, $manager->id, reason: 'out');

        $rows = app(StockMovementService::class)->exportRows(app(StockMovementService::class)->filters(['direction' => 'out']));
        $this->assertCount(2, $rows); // heading + the one outgoing row
        $this->assertSame('Exported', $rows[1][1]);
        $this->assertSame(1.0, $rows[1][5]);

        $this->actingAs($manager)->get('/products/movements/export?direction=out')->assertOk()
            ->assertHeader('content-disposition');

        config(['pos.demo_mode' => true]);
        $this->actingAs($manager)->get('/products/movements/export')->assertSessionHasErrors('demo');
        // the screen itself still works in a demo build
        $this->actingAs($manager)->get('/products/movements')->assertOk();
    }
}
