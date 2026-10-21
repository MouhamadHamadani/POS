<?php

namespace Tests\Feature\Sales;

use App\Facades\Feature;
use App\Models\AuditLog;
use App\Models\HeldSale;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\User;
use App\Services\ReceiptRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * On-site vs online: a label on the sale ("online" = an order taken by WhatsApp / Instagram /
 * phone and rung up by hand), behind the `sales_channels` feature. It changes no price, stock,
 * tax or loyalty rule, and selling works whether the feature is on or off.
 */
class SaleChannelTest extends TestCase
{
    use RefreshDatabase;

    private function cashier(): User
    {
        $user = User::factory()->create();
        Shift::create([
            'user_id' => $user->id, 'opened_at' => now(),
            'opening_cash_usd' => 0, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN,
        ]);

        return $user;
    }

    private function product(): Product
    {
        return Product::factory()->create(['price_usd' => 10, 'cost_usd' => 4, 'stock_qty' => 100, 'is_taxable' => false]);
    }

    private function sell(User $user, Product $product, array $extra = [])
    {
        return $this->actingAs($user)->postJson('/pos/api/sales', $extra + [
            'cart' => [['product_id' => $product->id, 'qty' => 1]],
            'payment' => ['method' => 'cash_usd', 'amount_usd' => 10],
        ]);
    }

    private function starter(): void
    {
        Setting::set('tier', 'starter', 'license');
    }

    // --- Recording it ---------------------------------------------------------------

    public function test_a_sale_with_no_channel_is_on_site(): void
    {
        $this->sell($this->cashier(), $this->product())->assertCreated()->assertJsonPath('sale.channel', 'onsite');

        $sale = Sale::sole();
        $this->assertSame('onsite', $sale->channel);
        $this->assertNull($sale->channel_source);
    }

    public function test_an_online_sale_keeps_its_source(): void
    {
        $this->sell($this->cashier(), $this->product(), ['channel' => 'online', 'channel_source' => 'whatsapp'])
            ->assertCreated()->assertJsonPath('sale.channel', 'online')->assertJsonPath('sale.channel_source', 'whatsapp');

        $sale = Sale::sole();
        $this->assertTrue($sale->isOnline());
        $this->assertSame('WhatsApp', $sale->channelSourceLabel());
    }

    public function test_an_online_sale_may_leave_the_source_blank(): void
    {
        $this->sell($this->cashier(), $this->product(), ['channel' => 'online', 'channel_source' => null])->assertCreated();

        $this->assertSame('online', Sale::sole()->channel);
        $this->assertNull(Sale::sole()->channel_source);
    }

    public function test_the_source_is_dropped_when_the_sale_is_on_site(): void
    {
        $this->sell($this->cashier(), $this->product(), ['channel' => 'onsite', 'channel_source' => 'instagram'])->assertCreated();
        $this->sell($this->cashier(), $this->product(), ['channel_source' => 'phone'])->assertCreated();

        $this->assertSame(2, Sale::where('channel', 'onsite')->whereNull('channel_source')->count());
    }

    public function test_values_the_enum_does_not_have_are_refused(): void
    {
        $cashier = $this->cashier();
        $product = $this->product();

        $this->sell($cashier, $product, ['channel' => 'carrier-pigeon'])->assertStatus(422);
        $this->sell($cashier, $product, ['channel' => 'online', 'channel_source' => 'tiktok'])->assertStatus(422);
        $this->assertSame(0, Sale::count());
    }

    public function test_the_channel_changes_nothing_about_what_is_charged_or_stocked(): void
    {
        $cashier = $this->cashier();
        $product = $this->product();

        $this->sell($cashier, $product)->assertCreated();
        $this->sell($cashier, $product, ['channel' => 'online', 'channel_source' => 'facebook'])->assertCreated();

        [$a, $b] = Sale::orderBy('id')->get();
        $this->assertEquals($a->total_usd, $b->total_usd);
        $this->assertEquals($a->subtotal_usd, $b->subtotal_usd);
        $this->assertEquals(98, $product->fresh()->stock_qty);
    }

    public function test_the_audit_entry_names_the_channel(): void
    {
        $this->sell($this->cashier(), $this->product(), ['channel' => 'online', 'channel_source' => 'phone'])->assertCreated();

        $log = AuditLog::where('action', 'sale')->latest('id')->first();
        $this->assertSame('online', $log->new_values['channel']);
        $this->assertSame('phone', $log->new_values['channel_source']);
    }

    public function test_rows_that_predate_the_column_read_as_on_site(): void
    {
        $user = User::factory()->create();
        // Built without a channel, the way every sale was before this column existed.
        $sale = Sale::create([
            'receipt_number' => 'OLD-1', 'user_id' => $user->id, 'subtotal_usd' => 5, 'total_usd' => 5, 'exchange_rate' => 90000,
            'payment_method' => 'cash_usd', 'status' => Sale::STATUS_COMPLETED,
        ]);

        $this->assertSame('onsite', $sale->fresh()->channel);
        $this->assertNull($sale->fresh()->channel_source);
    }

    // --- The feature gate -----------------------------------------------------------------

    public function test_with_the_feature_off_everything_is_forced_on_site_and_selling_still_works(): void
    {
        $this->starter();
        $this->assertFalse(Feature::enabled('sales_channels'));

        $this->sell($this->cashier(), $this->product(), ['channel' => 'online', 'channel_source' => 'whatsapp'])
            ->assertCreated()->assertJsonPath('sale.channel', 'onsite');

        $sale = Sale::sole();
        $this->assertSame('onsite', $sale->channel);
        $this->assertNull($sale->channel_source);
    }

    public function test_the_till_offers_the_toggle_only_with_the_feature(): void
    {
        $cashier = $this->cashier();

        $this->actingAs($cashier)->get('/pos')->assertOk()
            ->assertSee("@click=\"setChannel('online')\"", false)->assertSee(__('enums.sale_channel.online'))->assertSee(__('enums.sale_channel_source.whatsapp'));

        $this->starter();
        $this->actingAs($cashier)->get('/pos')->assertOk()
            ->assertDontSee("@click=\"setChannel('online')\"", false)->assertDontSee(__('enums.sale_channel_source.whatsapp'));
    }

    public function test_the_history_filter_and_badge_follow_the_feature_and_stored_values_stay_readable(): void
    {
        $manager = User::factory()->manager()->create();
        $cashier = $this->cashier();
        $this->sell($cashier, $this->product(), ['channel' => 'online', 'channel_source' => 'instagram'])->assertCreated();
        $this->sell($cashier, $this->product())->assertCreated();
        $online = Sale::where('channel', 'online')->sole();
        $onsite = Sale::where('channel', 'onsite')->sole();
        // A row is identified by its link: the receipt number also sits in the search box's placeholder.
        $row = fn (Sale $s) => '/sales/'.$s->id.'?';

        // On: the filter works and the column is there.
        $page = $this->actingAs($manager)->get('/sales?channel=online')->assertOk()
            ->assertSee('name="channel"', false)->assertSee($row($online), false)->assertDontSee($row($onsite), false);
        $page->assertSee(__('enums.sale_channel_source.instagram'));
        $this->actingAs($manager)->get('/sales?channel=onsite')->assertSee($row($onsite), false)->assertDontSee($row($online), false);
        $this->actingAs($manager)->get('/sales?channel=nonsense')->assertSessionHasErrors('channel');

        // Off: no filter, no column, a stale ?channel= is ignored, and the stored value is still readable.
        $this->starter();
        $this->actingAs($manager)->get('/sales?channel=online')->assertOk()
            ->assertDontSee('name="channel"', false)->assertSee($row($online), false)->assertSee($row($onsite), false);
        $this->actingAs($manager)->get(route('sales.show', $online))->assertOk()
            ->assertSee(__('sales.channel'))->assertSee(__('enums.sale_channel_source.instagram'));
        $this->actingAs($manager)->get(route('sales.show', $onsite))->assertOk()->assertDontSee(__('sales.channel'));
    }

    // --- Hold and recall ---------------------------------------------------------------------

    public function test_a_hold_comes_back_on_the_channel_it_was_parked_with(): void
    {
        $cashier = $this->cashier();
        $product = $this->product();

        $this->actingAs($cashier)->postJson('/pos/api/holds', [
            'cart' => [['product_id' => $product->id, 'qty' => 1]], 'channel' => 'online', 'channel_source' => 'facebook',
        ])->assertCreated();
        $this->assertSame('online', HeldSale::sole()->channel);
        $this->assertSame('facebook', HeldSale::sole()->channel_source);

        $this->actingAs($cashier)->postJson('/pos/api/holds/'.HeldSale::sole()->id.'/recall')
            ->assertOk()->assertJsonPath('channel', 'online')->assertJsonPath('channel_source', 'facebook');
    }

    public function test_a_hold_made_without_a_channel_is_on_site_and_one_recalled_with_the_feature_off_is_too(): void
    {
        $cashier = $this->cashier();
        $product = $this->product();

        $this->actingAs($cashier)->postJson('/pos/api/holds', ['cart' => [['product_id' => $product->id, 'qty' => 1]]])->assertCreated();
        $this->assertSame('onsite', HeldSale::sole()->channel);
        HeldSale::sole()->delete();

        $this->actingAs($cashier)->postJson('/pos/api/holds', [
            'cart' => [['product_id' => $product->id, 'qty' => 1]], 'channel' => 'online', 'channel_source' => 'phone',
        ])->assertCreated();

        $this->starter();
        $this->actingAs($cashier)->postJson('/pos/api/holds/'.HeldSale::sole()->id.'/recall')
            ->assertOk()->assertJsonPath('channel', 'onsite')->assertJsonPath('channel_source', null);
    }

    // --- Receipts -------------------------------------------------------------------------------

    public function test_only_an_online_order_prints_the_online_line(): void
    {
        $manager = User::factory()->manager()->create();
        $cashier = $this->cashier();
        $this->sell($cashier, $this->product(), ['channel' => 'online', 'channel_source' => 'whatsapp'])->assertCreated();
        $this->sell($cashier, $this->product())->assertCreated();
        $online = Sale::where('channel', 'online')->sole();
        $onsite = Sale::where('channel', 'onsite')->sole();

        foreach (['thermal', 'a4'] as $format) {
            $this->actingAs($manager)->get(route('receipts.print', ['sale' => $online, 'format' => $format, 'auto' => 0]))
                ->assertOk()->assertSee('Online order')->assertSee('طلب أونلاين')->assertSee('WhatsApp')->assertSee('واتساب');
            $this->actingAs($manager)->get(route('receipts.print', ['sale' => $onsite, 'format' => $format, 'auto' => 0]))
                ->assertOk()->assertDontSee('Online order');
        }

        $tokens = fn (Sale $s) => (new \ReflectionMethod(ReceiptRenderer::class, 'tokens'))->invoke(null, $s, [])['channel'];
        $this->assertSame('Online order · WhatsApp', $tokens($online));
        $this->assertSame('', $tokens($onsite));
    }
}
