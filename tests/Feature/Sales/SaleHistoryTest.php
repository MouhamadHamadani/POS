<?php

namespace Tests\Feature\Sales;

use App\Models\Customer;
use App\Models\InventoryAdjustment;
use App\Models\Product;
use App\Models\ReturnTransaction;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\User;
use App\Services\SaleHistoryService;
use App\Services\StockMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sales History: who sees which sales, the filters, the detail page and its
 * actions. Reprinting itself is ReprintCopyTest.
 */
class SaleHistoryTest extends TestCase
{
    use MakesSales, RefreshDatabase;

    // ---- ownership -----------------------------------------------------------

    public function test_a_cashier_lists_only_their_own_sales_and_a_manager_lists_all(): void
    {
        $alice = $this->cashier();
        $bob = $this->cashier();
        $this->sale($alice, ['receipt_number' => 'MINE-1']);
        $this->sale($bob, ['receipt_number' => 'THEIRS-1']);

        $this->actingAs($alice)->get(route('sales.index'))
            ->assertOk()->assertSee('MINE-1')->assertDontSee('THEIRS-1');

        $this->actingAs(User::factory()->manager()->create())->get(route('sales.index'))
            ->assertOk()->assertSee('MINE-1')->assertSee('THEIRS-1');
    }

    public function test_another_cashiers_sale_looks_exactly_like_one_that_does_not_exist(): void
    {
        $alice = $this->cashier();
        $theirs = $this->sale($this->cashier(), ['receipt_number' => 'THEIRS-1']);

        $this->actingAs($alice);
        // Detail: same 404 as an id that was never used.
        $this->get(route('sales.show', $theirs->id))->assertNotFound();
        $this->get(route('sales.show', $theirs->id + 1000))->assertNotFound();

        // Search: typing their receipt number finds nothing, same page as a number that was never issued.
        $real = $this->get(route('sales.index', ['q' => 'THEIRS-1']))->assertOk()->assertSee(__('sales.none'))->assertDontSee(route('sales.show', $theirs->id));
        $fake = $this->get(route('sales.index', ['q' => 'NOPE-9']))->assertOk()->assertSee(__('sales.none'));
        $this->assertSame(substr_count($real->getContent(), '<tr'), substr_count($fake->getContent(), '<tr'));
    }

    public function test_a_cashier_cannot_widen_the_list_with_the_cashier_filter(): void
    {
        $alice = $this->cashier();
        $bob = $this->cashier();
        $this->sale($bob, ['receipt_number' => 'THEIRS-1']);

        $this->actingAs($alice)->get(route('sales.index', ['user_id' => $bob->id]))
            ->assertOk()->assertDontSee('THEIRS-1');
    }

    public function test_the_stock_role_has_no_sales_history(): void
    {
        $sale = $this->sale($this->cashier());
        $stock = User::factory()->stock()->create();

        $this->actingAs($stock)->get(route('sales.index'))->assertForbidden();
        $this->actingAs($stock)->get(route('sales.show', $sale->id))->assertForbidden();
    }

    public function test_a_cashier_can_open_their_own_sale_after_their_shift_is_closed(): void
    {
        $alice = $this->cashier();
        $sale = $this->sale($alice, ['receipt_number' => 'MINE-1']);
        Shift::where('user_id', $alice->id)->update(['status' => Shift::STATUS_CLOSED, 'closed_at' => now()]);

        $this->actingAs($alice)->get(route('sales.index'))->assertOk()->assertSee('MINE-1');
        $this->actingAs($alice)->get(route('sales.show', $sale->id))->assertOk()->assertSee('MINE-1');
    }

    // ---- filters -------------------------------------------------------------

    public function test_filters_by_receipt_prefix_customer_name_and_phone(): void
    {
        $alice = $this->cashier();
        $customer = Customer::create(['name' => 'Zeinab Haddad', 'phone' => '+96170123456']);
        $this->sale($alice, ['receipt_number' => 'ABC-0001', 'customer_id' => $customer->id]);
        $this->sale($alice, ['receipt_number' => 'XYZ-0002']);
        $this->actingAs($alice);

        $this->get(route('sales.index', ['q' => 'ABC']))->assertSee('ABC-0001')->assertDontSee('XYZ-0002');
        $this->get(route('sales.index', ['q' => 'XYZ-0002']))->assertSee('XYZ-0002')->assertDontSee('ABC-0001');
        $this->get(route('sales.index', ['q' => 'Zeinab']))->assertSee('ABC-0001')->assertDontSee('XYZ-0002');
        $this->get(route('sales.index', ['q' => '70123456']))->assertSee('ABC-0001')->assertDontSee('XYZ-0002');
    }

    public function test_a_deleted_customers_sales_stay_searchable_and_keep_their_name(): void
    {
        $customer = Customer::create(['name' => 'Gone Customer', 'phone' => '111']);
        $sale = $this->sale($this->cashier(), ['receipt_number' => 'ABC-0001', 'customer_id' => $customer->id]);
        $customer->delete();

        $this->actingAs(User::factory()->manager()->create());
        $this->get(route('sales.index', ['q' => 'Gone']))->assertSee('ABC-0001')->assertSee('Gone Customer');
        $this->get(route('sales.show', $sale->id))->assertOk()->assertSee('Gone Customer');
    }

    public function test_dates_default_to_today_but_a_text_search_looks_through_every_date(): void
    {
        $alice = $this->cashier();
        $this->sale($alice, ['receipt_number' => 'TODAY-1']);
        $this->sale($alice, ['receipt_number' => 'OLD-1', 'created_at' => now()->subDays(20)]);
        $this->actingAs($alice);

        $this->get(route('sales.index'))->assertSee('TODAY-1')->assertDontSee('OLD-1');
        $this->get(route('sales.index', ['q' => 'OLD-1']))->assertSee('OLD-1');
        $this->get(route('sales.index', ['from' => now()->subDays(30)->toDateString(), 'to' => now()->toDateString()]))
            ->assertSee('TODAY-1')->assertSee('OLD-1');
        $this->get(route('sales.index', ['from' => now()->subDays(30)->toDateString(), 'to' => now()->subDays(10)->toDateString()]))
            ->assertDontSee('TODAY-1')->assertSee('OLD-1');
    }

    public function test_the_last_day_of_the_range_includes_the_whole_day(): void
    {
        $alice = $this->cashier();
        $this->sale($alice, ['receipt_number' => 'LATE-1', 'created_at' => now()->startOfDay()->setTime(23, 30)]);

        $this->actingAs($alice)->get(route('sales.index', ['from' => now()->toDateString(), 'to' => now()->toDateString()]))
            ->assertSee('LATE-1');
    }

    public function test_filters_by_status_and_payment_method_and_cashier(): void
    {
        $alice = $this->cashier();
        $bob = $this->cashier();
        $this->sale($alice, ['receipt_number' => 'DONE-1']);
        $this->sale($alice, ['receipt_number' => 'VOID-1', 'status' => Sale::STATUS_VOIDED]);
        $this->sale($alice, ['receipt_number' => 'CARD-1', 'payment_method' => Sale::METHOD_CARD]);
        $this->sale($bob, ['receipt_number' => 'BOB-1']);
        $this->actingAs(User::factory()->manager()->create());

        $this->get(route('sales.index', ['status' => Sale::STATUS_VOIDED]))->assertSee('VOID-1')->assertDontSee('DONE-1');
        $this->get(route('sales.index', ['payment_method' => Sale::METHOD_CARD]))->assertSee('CARD-1')->assertDontSee('DONE-1');
        $this->get(route('sales.index', ['user_id' => $bob->id]))->assertSee('BOB-1')->assertDontSee('DONE-1');
    }

    public function test_the_summary_is_one_count_and_total_that_leaves_voided_sales_out(): void
    {
        $alice = $this->cashier();
        $this->sale($alice, ['total_usd' => 10]);
        $this->sale($alice, ['total_usd' => 20]);
        $this->sale($alice, ['total_usd' => 100, 'status' => Sale::STATUS_VOIDED]);

        $service = app(SaleHistoryService::class);
        $summary = $service->summary($service->filters([]), $alice);

        $this->assertSame(3, $summary['count']);
        $this->assertEqualsWithDelta(30.0, $summary['total'], 0.001);
    }

    public function test_the_list_is_paginated(): void
    {
        $alice = $this->cashier();
        for ($i = 0; $i < SaleHistoryService::PER_PAGE + 3; $i++) {
            $this->sale($alice);
        }

        $this->actingAs($alice)->get(route('sales.index'))->assertOk()->assertSee('page=2');
    }

    // ---- detail --------------------------------------------------------------

    public function test_the_detail_shows_lines_totals_payment_and_linked_returns(): void
    {
        $manager = User::factory()->manager()->create();
        $sale = $this->sale($this->cashier(), [
            'receipt_number' => 'REC-D-1', 'price_tier' => 'wholesale',
            'subtotal_usd' => 10, 'tax_amount_usd' => 1.10, 'total_usd' => 11.10, 'total_lbp' => 1110000,
            'amount_tendered_usd' => 20, 'change_usd' => 8.90,
            'status' => Sale::STATUS_PARTIAL_REFUND,
        ]);
        $this->item($sale, 'Olive Oil', ['returned_qty' => 1]);
        $return = ReturnTransaction::create([
            'return_number' => 'RTN-D-1', 'sale_id' => $sale->id, 'user_id' => $manager->id,
            'reason' => 'defective', 'refund_method' => 'cash_usd', 'refund_amount_usd' => 5,
            'status' => ReturnTransaction::STATUS_COMPLETED,
        ]);

        $this->actingAs($manager)->get(route('sales.show', $sale->id))
            ->assertOk()
            ->assertSee('REC-D-1')->assertSee('Olive Oil')
            ->assertSee('$11.10')->assertSee('1,110,000')->assertSee('11%')
            ->assertSee('$20.00')->assertSee('$8.90')
            ->assertSee('RTN-D-1')->assertSee(route('returns.show', $return->id));
    }

    public function test_a_deleted_product_does_not_break_the_detail(): void
    {
        $sale = $this->sale($this->cashier());
        $item = $this->item($sale, 'Vanished Item');
        Product::findOrFail($item->product_id)->delete();

        $this->actingAs(User::factory()->manager()->create())
            ->get(route('sales.show', $sale->id))->assertOk()->assertSee('Vanished Item');
    }

    public function test_reprint_buttons_and_the_return_action_follow_the_sales_status(): void
    {
        $alice = $this->cashier();
        $done = $this->sale($alice, ['status' => Sale::STATUS_COMPLETED]);
        $partial = $this->sale($alice, ['status' => Sale::STATUS_PARTIAL_REFUND]);
        $refunded = $this->sale($alice, ['status' => Sale::STATUS_REFUNDED]);
        $voided = $this->sale($alice, ['status' => Sale::STATUS_VOIDED]);
        $held = $this->sale($alice, ['status' => Sale::STATUS_ON_HOLD]);
        $this->actingAs(User::factory()->manager()->create());

        $returnLink = fn (Sale $s) => route('returns.create', $s->id);
        $reprint = __('sales.reprint_receipt');

        $this->get(route('sales.show', $done->id))->assertSee($reprint)->assertSee($returnLink($done));
        $this->get(route('sales.show', $partial->id))->assertSee($reprint)->assertSee($returnLink($partial));
        // Not returnable: the same statuses the Returns screens refuse.
        $this->get(route('sales.show', $refunded->id))->assertSee($reprint)->assertDontSee($returnLink($refunded));
        $this->get(route('sales.show', $voided->id))->assertSee($reprint)->assertDontSee($returnLink($voided));
        // On hold: no receipt exists yet.
        $this->get(route('sales.show', $held->id))
            ->assertDontSee($reprint)->assertDontSee($returnLink($held))->assertSee(__('sales.held_no_receipt'));
    }

    public function test_the_return_action_needs_the_returns_feature(): void
    {
        Setting::set('tier', 'starter', 'license');
        $sale = $this->sale($this->cashier());

        $this->actingAs(User::factory()->manager()->create())->get(route('sales.show', $sale->id))
            ->assertOk()->assertDontSee(route('returns.create', $sale->id));
    }

    public function test_sales_history_is_not_a_tiered_feature(): void
    {
        Setting::set('tier', 'starter', 'license');
        $alice = $this->cashier();
        $this->sale($alice, ['receipt_number' => 'MINE-1']);

        $this->actingAs($alice)->get(route('sales.index'))->assertOk()->assertSee('MINE-1');
    }

    public function test_the_default_format_comes_first_and_the_filters_ride_along_to_the_detail(): void
    {
        Setting::set('receipt_format', 'a4', 'receipt');
        $alice = $this->cashier();
        $sale = $this->sale($alice, ['receipt_number' => 'ABC-1']);
        $this->actingAs($alice);

        $page = $this->get(route('sales.show', $sale->id))->getContent();
        $this->assertLessThan(strpos($page, __('sales.reprint_receipt')), strpos($page, __('sales.reprint_a4')));

        $this->get(route('sales.index', ['q' => 'ABC']))
            ->assertSee(e(route('sales.show', ['sale' => $sale->id, 'q' => 'ABC'])), false);
        $this->get(route('sales.show', ['sale' => $sale->id, 'q' => 'ABC', 'status' => 'completed']))
            ->assertSee(e(route('sales.index', ['q' => 'ABC', 'status' => 'completed'])), false);
    }

    // ---- receipt numbers elsewhere -------------------------------------------

    public function test_receipt_numbers_link_to_the_detail_only_when_the_viewer_can_open_it(): void
    {
        $alice = $this->cashier();
        $bob = $this->cashier();
        $mine = $this->sale($alice, ['receipt_number' => 'MINE-1']);
        $theirs = $this->sale($bob, ['receipt_number' => 'THEIRS-1']);
        foreach ([$mine, $theirs] as $i => $s) {
            ReturnTransaction::create([
                'return_number' => "RTN-L-{$i}", 'sale_id' => $s->id, 'user_id' => $alice->id,
                'reason' => 'other', 'refund_method' => 'cash_usd', 'refund_amount_usd' => 1,
                'status' => ReturnTransaction::STATUS_PENDING,
            ]);
        }

        $this->actingAs($alice)->get(route('returns.index'))
            ->assertSee(route('sales.show', $mine->id))
            ->assertSee('THEIRS-1')->assertDontSee(route('sales.show', $theirs->id));

        $this->actingAs(User::factory()->manager()->create())->get(route('returns.index'))
            ->assertSee(route('sales.show', $mine->id))->assertSee(route('sales.show', $theirs->id));
    }

    public function test_dashboard_customer_page_and_daily_sales_open_sales_history(): void
    {
        $manager = User::factory()->manager()->create();
        $customer = Customer::create(['name' => 'Linked Customer']);
        $sale = $this->sale($this->cashier(), ['customer_id' => $customer->id]);
        $this->actingAs($manager);

        $this->get('/dashboard')->assertSee(route('sales.show', $sale->id));
        $this->get(route('customers.show', $customer->id))->assertSee(route('sales.show', $sale->id));
        $today = now()->toDateString();
        $this->get(route('reports.daily-sales', ['from' => $today, 'to' => $today]))
            ->assertSee(e(route('sales.index', ['from' => $today, 'to' => $today, 'status' => Sale::STATUS_COMPLETED])), false);
    }

    public function test_stock_movements_links_a_sale_to_its_detail_for_managers_but_not_stock_keepers(): void
    {
        $sale = $this->sale($this->cashier());
        $product = Product::factory()->create();
        InventoryAdjustment::create([
            'product_id' => $product->id, 'user_id' => $sale->user_id, 'type' => InventoryAdjustment::TYPE_SALE,
            'qty_before' => 5, 'qty_change' => -1, 'qty_after' => 4,
            'reference_type' => 'sale', 'reference_id' => $sale->id,
        ]);
        $service = app(StockMovementService::class);
        $url = fn () => $service->paginate($service->filters(['from' => now()->toDateString(), 'to' => now()->toDateString()]))
            ->getCollection()->first()->ref_url;

        $this->actingAs(User::factory()->manager()->create());
        $this->assertSame(route('sales.show', $sale->id), $url());

        $this->actingAs(User::factory()->stock()->create());
        $this->assertNull($url());
    }
}
