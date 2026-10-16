<?php

namespace Tests\Feature\Reports;

use App\Models\Category;
use App\Models\InventoryAdjustment;
use App\Models\Product;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\ReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Inventory by date: stock_at(D) = current stock - movements after D, and the
 * movement-between-dates mode that must always close on stock_at(to).
 */
class InventoryByDateTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private InventoryService $inventory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->manager()->create();
        $this->inventory = app(InventoryService::class);
    }

    private function day(int $daysAgo): Carbon
    {
        return now()->subDays($daysAgo)->setTime(12, 0);
    }

    /** A tracked product that "existed since" $createdDaysAgo, with an empty shelf. */
    private function product(string $name, int $createdDaysAgo = 30, array $extra = []): Product
    {
        $p = Product::factory()->create(array_merge(['name' => $name, 'stock_qty' => 0, 'cost_usd' => 4.00, 'price_usd' => 10.00], $extra));
        Product::whereKey($p->id)->update(['created_at' => $this->day($createdDaysAgo)]);

        return $p;
    }

    /** Log a movement and back-date it, the way history would really have accrued. */
    private function move(Product $p, string $type, float $delta, int $daysAgo): void
    {
        $this->inventory->adjust($p, $delta, $type, $this->manager->id, reason: 'test');
        InventoryAdjustment::where('product_id', $p->id)->latest('id')->limit(1)->update(['created_at' => $this->day($daysAgo)]);
    }

    /** The rice story used by several tests (current stock 105). */
    private function rice(): Product
    {
        $rice = $this->product('Rice 1kg');
        $this->move($rice, InventoryAdjustment::TYPE_OPENING, 100, 20);
        $this->move($rice, InventoryAdjustment::TYPE_PO_RECEIVE, 50, 10);   // 150
        $this->move($rice, InventoryAdjustment::TYPE_SALE, -30, 5);         // 120
        $this->move($rice, InventoryAdjustment::TYPE_REMOVE, -5, 2);        // 115
        $this->move($rice, InventoryAdjustment::TYPE_SALE, -10, 1);         // 105

        return $rice;
    }

    private function qtyOn(int $daysAgo, ?int $category = null): array
    {
        return collect(app(ReportService::class)->stockOnDate($this->day($daysAgo)->startOfDay(), $category)['rows'])->pluck('qty', 'name')->all();
    }

    public function test_stock_on_date_walks_the_history_back_from_the_current_quantity(): void
    {
        $this->rice();

        $this->assertEqualsWithDelta(105, $this->qtyOn(0)['Rice 1kg'], 0.0001);  // today
        $this->assertEqualsWithDelta(105, $this->qtyOn(1)['Rice 1kg'], 0.0001);  // yesterday's sale is ON that day
        $this->assertEqualsWithDelta(115, $this->qtyOn(2)['Rice 1kg'], 0.0001);  // end of the day before it
        $this->assertEqualsWithDelta(120, $this->qtyOn(3)['Rice 1kg'], 0.0001);
        $this->assertEqualsWithDelta(120, $this->qtyOn(5)['Rice 1kg'], 0.0001);  // the sale is ON that day, so it counts
        $this->assertEqualsWithDelta(150, $this->qtyOn(6)['Rice 1kg'], 0.0001);
        $this->assertEqualsWithDelta(100, $this->qtyOn(20)['Rice 1kg'], 0.0001);
        $this->assertEqualsWithDelta(0, $this->qtyOn(21)['Rice 1kg'], 0.0001);   // before the opening row
    }

    public function test_values_use_current_cost_and_retail_and_totals_add_up(): void
    {
        $this->rice();
        $sugar = $this->product('Sugar 1kg', 30, ['cost_usd' => 2.00, 'price_usd' => 5.00]);
        $this->move($sugar, InventoryAdjustment::TYPE_OPENING, 40, 25);

        // 6 days ago: rice 150 x 4 / x 10, sugar 40 x 2 / x 5
        $data = app(ReportService::class)->stockOnDate($this->day(6)->startOfDay());
        $rows = collect($data['rows'])->keyBy('name');

        $this->assertEqualsWithDelta(600.00, $rows['Rice 1kg']['value_cost'], 0.001);
        $this->assertEqualsWithDelta(1500.00, $rows['Rice 1kg']['value_retail'], 0.001);
        $this->assertEqualsWithDelta(80.00, $rows['Sugar 1kg']['value_cost'], 0.001);
        $this->assertEqualsWithDelta(680.00, $data['totals']['value_cost'], 0.001);
        $this->assertEqualsWithDelta(1700.00, $data['totals']['value_retail'], 0.001);

        // Cost changed since: the report values the past quantity at today's cost.
        Product::where('name', 'Rice 1kg')->update(['cost_usd' => 5.00]);
        $this->assertEqualsWithDelta(750.00, collect(app(ReportService::class)->stockOnDate($this->day(6)->startOfDay())['rows'])->keyBy('name')['Rice 1kg']['value_cost'], 0.001);
    }

    public function test_untracked_products_and_products_created_after_the_date_are_left_out(): void
    {
        $this->rice();
        $service = $this->product('Delivery fee', 30, ['track_stock' => false]);
        $late = $this->product('Brand new', 2);
        $this->move($late, InventoryAdjustment::TYPE_OPENING, 7, 2);

        $sixDaysAgo = array_keys($this->qtyOn(6));
        $this->assertContains('Rice 1kg', $sixDaysAgo);
        $this->assertNotContains('Brand new', $sixDaysAgo);   // did not exist yet
        $this->assertNotContains('Delivery fee', $sixDaysAgo);
        $this->assertContains('Brand new', array_keys($this->qtyOn(0)));
        $this->assertNotContains('Delivery fee', array_keys($this->qtyOn(0)));
    }

    public function test_a_product_deleted_since_still_appears_on_a_date_it_existed(): void
    {
        $gone = $this->product('Discontinued');
        $this->move($gone, InventoryAdjustment::TYPE_OPENING, 12, 20);
        $gone->delete();
        Product::withTrashed()->whereKey($gone->id)->update(['deleted_at' => $this->day(3)]);

        $this->assertEqualsWithDelta(12, $this->qtyOn(10)['Discontinued'], 0.0001);
        $this->assertArrayNotHasKey('Discontinued', $this->qtyOn(1));
    }

    public function test_category_filter(): void
    {
        $drinks = Category::factory()->create(['name' => 'Drinks']);
        $a = $this->product('Cola', 30, ['category_id' => $drinks->id]);
        $b = $this->product('Chips');
        $this->move($a, InventoryAdjustment::TYPE_OPENING, 3, 10);
        $this->move($b, InventoryAdjustment::TYPE_OPENING, 4, 10);

        $this->assertSame(['Cola'], array_keys($this->qtyOn(0, $drinks->id)));
    }

    public function test_movement_mode_opening_in_out_and_closing(): void
    {
        $this->rice();

        $data = app(ReportService::class)->inventoryMovement($this->day(10)->startOfDay(), $this->day(5)->endOfDay());
        $row = collect($data['rows'])->firstWhere('name', 'Rice 1kg');

        $this->assertEqualsWithDelta(100, $row['opening'], 0.0001);   // stock on day -11
        $this->assertEqualsWithDelta(50, $row['qty_in'], 0.0001);
        $this->assertEqualsWithDelta(30, $row['qty_out'], 0.0001);
        $this->assertEqualsWithDelta(120, $row['closing'], 0.0001);
    }

    public function test_only_products_with_movement_filter(): void
    {
        $this->rice();
        $idle = $this->product('Idle');
        $this->move($idle, InventoryAdjustment::TYPE_OPENING, 9, 20);

        $service = app(ReportService::class);
        $from = $this->day(10)->startOfDay();
        $to = $this->day(5)->endOfDay();

        $this->assertEqualsCanonicalizing(['Rice 1kg', 'Idle'], array_column($service->inventoryMovement($from, $to)['rows'], 'name'));
        $this->assertSame(['Rice 1kg'], array_column($service->inventoryMovement($from, $to, null, true)['rows'], 'name'));
    }

    /** closing == stock_at(to) and opening == stock_at(from - 1 day), whatever mix of movements built the history. */
    public function test_closing_always_equals_stock_on_the_end_date(): void
    {
        $a = $this->product('Mixed A');
        $b = $this->product('Mixed B');
        $c = $this->product('Mixed C', 14);

        $this->move($a, InventoryAdjustment::TYPE_OPENING, 40, 25);
        $this->move($a, InventoryAdjustment::TYPE_PO_RECEIVE, 12.5, 19);
        $this->move($a, InventoryAdjustment::TYPE_SALE, -7.25, 16);
        $this->move($a, InventoryAdjustment::TYPE_RETURN, 2, 15);
        $this->move($a, InventoryAdjustment::TYPE_REMOVE, -3, 9);
        $this->move($a, InventoryAdjustment::TYPE_ADD, 6, 9);
        $this->move($b, InventoryAdjustment::TYPE_OPENING, 10, 22);
        $this->move($b, InventoryAdjustment::TYPE_SALE, -4, 12);
        $this->move($b, InventoryAdjustment::TYPE_SALE, -6, 12);
        $this->move($b, InventoryAdjustment::TYPE_ADD, 30, 3);
        $this->move($c, InventoryAdjustment::TYPE_OPENING, 5, 14);
        $this->move($c, InventoryAdjustment::TYPE_SALE, -2, 8);
        // an absolute stock count, which can move the shelf either way
        $this->inventory->setStock($a, 20, $this->manager->id, 'recount');
        InventoryAdjustment::where('product_id', $a->id)->latest('id')->limit(1)->update(['created_at' => $this->day(4)]);
        $this->move($b, InventoryAdjustment::TYPE_COUNT, 0, 2);

        $service = app(ReportService::class);
        $ranges = [[22, 0], [20, 10], [16, 15], [12, 12], [9, 4], [8, 1], [3, 0], [30, 25]];
        $checked = 0;

        foreach ($ranges as [$fromAgo, $toAgo]) {
            $from = $this->day($fromAgo)->startOfDay();
            $to = $this->day($toAgo)->endOfDay();

            $closing = collect($service->stockOnDate($to->copy()->startOfDay())['rows'])->pluck('qty', 'id');
            $opening = collect($service->stockOnDate($from->copy()->subDay()->startOfDay())['rows'])->pluck('qty', 'id');

            foreach ($service->inventoryMovement($from, $to)['rows'] as $row) {
                $this->assertEqualsWithDelta($row['opening'] + $row['qty_in'] - $row['qty_out'], $row['closing'], 0.0001, "{$row['name']} {$fromAgo}->{$toAgo}: closing = opening + in - out");
                $this->assertEqualsWithDelta((float) ($closing[$row['id']] ?? 0), $row['closing'], 0.0001, "{$row['name']} {$fromAgo}->{$toAgo}: closing = stock_at(to)");
                $this->assertEqualsWithDelta((float) ($opening[$row['id']] ?? 0), $row['opening'], 0.0001, "{$row['name']} {$fromAgo}->{$toAgo}: opening = stock_at(from-1)");
                $checked++;
            }
        }

        $this->assertGreaterThan(15, $checked);
    }

    public function test_it_is_one_grouped_query_not_one_per_product(): void
    {
        foreach (range(1, 60) as $i) {
            $p = $this->product("Item {$i}");
            $this->move($p, InventoryAdjustment::TYPE_OPENING, $i, 10);
            $this->move($p, InventoryAdjustment::TYPE_SALE, -1, 3);
        }

        $queries = [];
        DB::listen(function ($q) use (&$queries) { $queries[] = $q->sql; });

        $report = app(ReportService::class);
        $this->assertCount(60, $report->stockOnDate(now())['rows']);
        $this->assertLessThanOrEqual(1, count($queries));

        $queries = [];
        $this->assertCount(60, $report->inventoryMovement($this->day(12), now())['rows']);
        $this->assertLessThanOrEqual(1, count($queries));
    }

    public function test_screen_exports_and_notes(): void
    {
        $this->rice();
        $day = $this->day(6)->format('Y-m-d');

        $this->actingAs($this->manager)->get("/reports/inventory/by-date?date={$day}")
            ->assertOk()
            ->assertSee('Inventory by Date')
            ->assertSee('Rice 1kg')
            ->assertSee('600.00')     // 150 x 4.00
            ->assertSee('1,500.00')   // 150 x 10.00
            ->assertSee('current cost price')
            ->assertSee('Figures go back to when the business started using the system.');

        $from = $this->day(10)->format('Y-m-d');
        $to = $this->day(5)->format('Y-m-d');
        $this->actingAs($this->manager)->get("/reports/inventory/by-date?mode=movement&from={$from}&to={$to}")
            ->assertOk()->assertSee('Opening qty')->assertSee('Closing qty')->assertSee('Rice 1kg')
            ->assertDontSee('current cost price');

        foreach (["mode=on_date&date={$day}", "mode=movement&from={$from}&to={$to}&only_moved=1"] as $query) {
            $csv = $this->actingAs($this->manager)->get("/reports/inventory/by-date?{$query}&format=xlsx");
            $csv->assertOk()->assertHeader('content-disposition');

            $pdf = $this->actingAs($this->manager)->get("/reports/inventory/by-date?{$query}&format=pdf");
            $pdf->assertOk();
            $this->assertStringStartsWith('%PDF', $pdf->getContent());
        }

        $this->actingAs($this->manager)->get('/reports/inventory/by-date?mode=movement&from=2026-02-01&to=2026-01-01')
            ->assertSessionHasErrors('to');
        $this->actingAs($this->manager)->get('/reports')->assertSee('Inventory by Date');
    }

    public function test_arabic_names_render_in_the_pdf_and_screen(): void
    {
        $this->rice();
        Product::where('name', 'Rice 1kg')->update(['name_ar' => 'أرز 1 كلغ']);
        $arabic = User::factory()->manager()->create(['language' => 'ar']);
        $day = $this->day(6)->format('Y-m-d');

        $this->actingAs($arabic)->get("/reports/inventory/by-date?date={$day}")
            ->assertOk()->assertSee('<html lang="ar" dir="rtl">', false)->assertSee('أرز 1 كلغ')->assertSee('المخزون بتاريخ');

        foreach (["mode=on_date&date={$day}", 'mode=movement'] as $query) {
            $pdf = $this->actingAs($arabic)->get("/reports/inventory/by-date?{$query}&format=pdf");
            $pdf->assertOk();
            $this->assertStringStartsWith('%PDF', $pdf->getContent());
        }

        // The PDF HTML hands dompdf shaped Arabic and reversed columns, never the raw logical string.
        app()->setLocale('ar');
        $html = view('reports.inventory-by-date-pdf', app(ReportService::class)->stockOnDate($this->day(6)) + [
            'mode' => 'on_date', 'date' => $this->day(6), 'from' => $this->day(6), 'to' => $this->day(6), 'title' => 'المخزون بتاريخ',
        ])->render();
        $this->assertStringContainsString("font-family: 'Amiri'", $html);
        $this->assertStringNotContainsString('أرز 1 كلغ', $html);
        $this->assertMatchesRegularExpression('/[\x{FE70}-\x{FEFF}]/u', $html);
    }

    public function test_only_admin_and_manager_may_open_it(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get('/reports/inventory/by-date')->assertOk();
        $this->actingAs($this->manager)->get('/reports/inventory/by-date')->assertOk();
        $this->actingAs(User::factory()->create())->get('/reports/inventory/by-date')->assertForbidden();
        $this->actingAs(User::factory()->stock()->create())->get('/reports/inventory/by-date')->assertForbidden();
    }

    public function test_exports_are_blocked_in_a_demo_build_but_the_screen_works(): void
    {
        config(['pos.demo_mode' => true]);

        $this->actingAs($this->manager)->get('/reports/inventory/by-date')->assertOk();
        $this->actingAs($this->manager)->get('/reports/inventory/by-date?format=pdf')->assertSessionHasErrors('demo');
        $this->actingAs($this->manager)->get('/reports/inventory/by-date?format=xlsx')->assertSessionHasErrors('demo');
    }
}
