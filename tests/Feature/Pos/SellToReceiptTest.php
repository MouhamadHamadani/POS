<?php

namespace Tests\Feature\Pos;

use App\Models\Product;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * End-to-end money path: cart -> SaleController@store -> SaleService ->
 * ReceiptController. Numbers here are hand-computed in the test body so a
 * regression in the totals shows up as a failing arithmetic assertion rather
 * than "the page rendered".
 */
class SellToReceiptTest extends TestCase
{
    use RefreshDatabase;

    private function cashierWithShift(): User
    {
        $cashier = User::factory()->create(['role' => User::ROLE_CASHIER, 'max_discount_pct' => 100]);

        Shift::create([
            'user_id' => $cashier->id,
            'opened_at' => now(),
            'opening_cash_usd' => 100,
            'opening_cash_lbp' => 0,
            'status' => Shift::STATUS_OPEN,
        ]);

        return $cashier;
    }

    private function vat(bool $inclusive = false): Tax
    {
        return Tax::create([
            'name' => $inclusive ? 'VAT incl' : 'Lebanese VAT',
            'rate' => 0.11,
            'is_inclusive' => $inclusive,
            'is_default' => true,
            'is_active' => true,
        ]);
    }

    public function test_multi_line_sale_with_discount_and_split_payment_is_recorded_correctly(): void
    {
        Setting::set('exchange_rate', 90000, 'currency', 'float');
        Setting::set('lbp_rounding_step', 1000, 'currency', 'int');

        $vat = $this->vat();
        $cashier = $this->cashierWithShift();

        $widget = Product::factory()->create([
            'name' => 'Widget', 'price_usd' => 10.00, 'cost_usd' => 4.00,
            'stock_qty' => 50, 'tax_id' => $vat->id, 'is_taxable' => true,
        ]);
        $gadget = Product::factory()->create([
            'name' => 'Gadget', 'price_usd' => 25.00, 'cost_usd' => 10.00,
            'stock_qty' => 50, 'tax_id' => $vat->id, 'is_taxable' => true,
        ]);

        // Hand-computed:
        //   Widget  3 x 10.00            = 30.00 net, VAT 3.30, line 33.30
        //   Gadget  1 x 25.00 less 5.00  = 20.00 net, VAT 2.20, line 22.20
        //   subtotal 55.00, discount 5.00, tax 5.50, total 55.50
        //   paid: card 20.00 + cash 40.00 -> cash due 35.50 -> change 4.50
        $response = $this->actingAs($cashier)->postJson('/pos/api/sales', [
            'cart' => [
                ['product_id' => $widget->id, 'qty' => 3, 'unit_price' => 10.00],
                ['product_id' => $gadget->id, 'qty' => 1, 'unit_price' => 25.00, 'discount_amount' => 5.00],
            ],
            'payment' => [
                'method' => 'split',
                'amount_usd' => 40.00,
                'amount_card' => 20.00,
                'card_type' => 'Visa',
            ],
        ]);

        $response->assertCreated();
        $sale = Sale::with('items')->findOrFail($response->json('sale.id'));

        $this->assertEqualsWithDelta(55.00, (float) $sale->subtotal_usd, 0.0001);
        $this->assertEqualsWithDelta(5.00, (float) $sale->discount_amount_usd, 0.0001);
        $this->assertEqualsWithDelta(5.50, (float) $sale->tax_amount_usd, 0.0001);
        $this->assertEqualsWithDelta(55.50, (float) $sale->total_usd, 0.0001);
        $this->assertEqualsWithDelta(4.50, (float) $sale->change_usd, 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $sale->change_lbp, 0.0001);

        // 55.50 * 90,000 = 4,995,000 LBP, already on the 1,000 rounding step.
        $this->assertEqualsWithDelta(4_995_000, (float) $sale->total_lbp, 0.5);

        // The sale total must equal the sum of its own line totals.
        $this->assertEqualsWithDelta(
            (float) $sale->total_usd,
            (float) $sale->items->sum('line_total_usd'),
            0.0001
        );

        // Stock moved.
        $this->assertEqualsWithDelta(47, (float) $widget->fresh()->stock_qty, 0.0001);
        $this->assertEqualsWithDelta(49, (float) $gadget->fresh()->stock_qty, 0.0001);

        // Cost is snapshotted on the line for later COGS reporting.
        $this->assertEqualsWithDelta(4.00, (float) $sale->items->firstWhere('product_name', 'Widget')->cost_usd, 0.0001);

        $this->assertDatabaseHas('audit_logs', ['action' => 'sale', 'model_id' => $sale->id]);
    }

    public function test_receipt_shows_the_same_totals_that_were_recorded(): void
    {
        Setting::set('exchange_rate', 90000, 'currency', 'float');
        Setting::set('business_name', 'Beirut Mini Market');

        $vat = $this->vat();
        $cashier = $this->cashierWithShift();
        $product = Product::factory()->create([
            'name' => 'Coffee 250g', 'price_usd' => 10.00, 'cost_usd' => 6.00,
            'tax_id' => $vat->id, 'is_taxable' => true,
        ]);

        $saleId = $this->actingAs($cashier)->postJson('/pos/api/sales', [
            'cart' => [['product_id' => $product->id, 'qty' => 2, 'unit_price' => 10.00]],
            'payment' => ['method' => 'cash_usd', 'amount_usd' => 30.00],
        ])->assertCreated()->json('sale.id');

        $sale = Sale::findOrFail($saleId);

        // 2 x 10.00 = 20.00 net + 2.20 VAT = 22.20; tendered 30.00 -> change 7.80
        $this->assertEqualsWithDelta(22.20, (float) $sale->total_usd, 0.0001);
        $this->assertEqualsWithDelta(7.80, (float) $sale->change_usd, 0.0001);

        $this->actingAs($cashier)->get("/pos/receipts/{$sale->id}/print")
            ->assertOk()
            ->assertSee('Beirut Mini Market')
            ->assertSee($sale->receipt_number)
            ->assertSee('Coffee 250g')
            ->assertSee('22.20')
            ->assertSee('7.80');
    }

    public function test_card_only_sale_records_no_cash_tendered_and_no_change(): void
    {
        $vat = $this->vat();
        $cashier = $this->cashierWithShift();
        $product = Product::factory()->create([
            'price_usd' => 50.00, 'cost_usd' => 20.00, 'tax_id' => $vat->id, 'is_taxable' => true,
        ]);

        // 50.00 net + 5.50 VAT = 55.50 charged to the card.
        $saleId = $this->actingAs($cashier)->postJson('/pos/api/sales', [
            'cart' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 50.00]],
            'payment' => ['method' => 'card', 'amount_card' => 55.50, 'card_type' => 'Visa', 'card_reference' => '1234'],
        ])->assertCreated()->json('sale.id');

        $sale = Sale::findOrFail($saleId);

        $this->assertEqualsWithDelta(55.50, (float) $sale->amount_card_usd, 0.0001);
        $this->assertNull($sale->amount_tendered_usd);
        $this->assertEqualsWithDelta(0.0, (float) $sale->change_usd, 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $sale->change_lbp, 0.0001);
    }

    public function test_inclusive_vat_is_not_charged_twice(): void
    {
        // Regression: totals used to be subtotal - discount + tax regardless of
        // inclusivity, so a 111.00 inclusive-VAT item was billed at 122.00 while
        // the POS screen showed 111.00.
        $vat = $this->vat(inclusive: true);
        $cashier = $this->cashierWithShift();
        $product = Product::factory()->create([
            'name' => 'Inclusive Item', 'price_usd' => 111.00, 'cost_usd' => 50.00,
            'tax_id' => $vat->id, 'is_taxable' => true,
        ]);

        $saleId = $this->actingAs($cashier)->postJson('/pos/api/sales', [
            'cart' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 111.00]],
            'payment' => ['method' => 'cash_usd', 'amount_usd' => 111.00],
        ])->assertCreated()->json('sale.id');

        $sale = Sale::with('items')->findOrFail($saleId);

        $this->assertEqualsWithDelta(111.00, (float) $sale->total_usd, 0.0001);
        $this->assertEqualsWithDelta(11.00, (float) $sale->tax_amount_usd, 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $sale->change_usd, 0.0001);
        $this->assertEqualsWithDelta(111.00, (float) $sale->items->sum('line_total_usd'), 0.0001);
    }

    public function test_a_cart_mixing_inclusive_and_exclusive_tax_adds_up_per_line(): void
    {
        $exclusive = $this->vat();
        $inclusive = Tax::create([
            'name' => 'VAT incl', 'rate' => 0.11, 'is_inclusive' => true,
            'is_default' => false, 'is_active' => true,
        ]);

        $cashier = $this->cashierWithShift();
        $exclusiveItem = Product::factory()->create([
            'price_usd' => 100.00, 'cost_usd' => 40.00, 'tax_id' => $exclusive->id, 'is_taxable' => true,
        ]);
        $inclusiveItem = Product::factory()->create([
            'price_usd' => 111.00, 'cost_usd' => 50.00, 'tax_id' => $inclusive->id, 'is_taxable' => true,
        ]);

        // exclusive line: 100.00 + 11.00 = 111.00; inclusive line: 111.00 flat.
        $saleId = $this->actingAs($cashier)->postJson('/pos/api/sales', [
            'cart' => [
                ['product_id' => $exclusiveItem->id, 'qty' => 1, 'unit_price' => 100.00],
                ['product_id' => $inclusiveItem->id, 'qty' => 1, 'unit_price' => 111.00],
            ],
            'payment' => ['method' => 'cash_usd', 'amount_usd' => 222.00],
        ])->assertCreated()->json('sale.id');

        $sale = Sale::with('items')->findOrFail($saleId);

        $this->assertEqualsWithDelta(222.00, (float) $sale->total_usd, 0.0001);
        $this->assertEqualsWithDelta(22.00, (float) $sale->tax_amount_usd, 0.0001);
    }

    public function test_underpayment_is_rejected_and_nothing_is_written(): void
    {
        $vat = $this->vat();
        $cashier = $this->cashierWithShift();
        $product = Product::factory()->create([
            'price_usd' => 10.00, 'stock_qty' => 5, 'tax_id' => $vat->id, 'is_taxable' => true,
        ]);

        $this->actingAs($cashier)->postJson('/pos/api/sales', [
            'cart' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 10.00]],
            'payment' => ['method' => 'cash_usd', 'amount_usd' => 5.00],
        ])->assertStatus(422);

        $this->assertSame(0, Sale::count());
        $this->assertEqualsWithDelta(5, (float) $product->fresh()->stock_qty, 0.0001);
    }
}
