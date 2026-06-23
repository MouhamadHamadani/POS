<?php

namespace Tests\Feature\Pos;

use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReceiptPrintTest extends TestCase
{
    use RefreshDatabase;

    private function shiftFor(User $user): Shift
    {
        return Shift::create([
            'user_id' => $user->id,
            'opened_at' => now(),
            'opening_cash_usd' => 0, 'opening_cash_lbp' => 0,
            'status' => Shift::STATUS_OPEN,
        ]);
    }

    private function sampleSale(User $cashier): Sale
    {
        $shift = $this->shiftFor($cashier);
        $product = \App\Models\Product::factory()->create(['name' => 'Test Item']);
        $sale = Sale::create([
            'receipt_number' => 'REC-TEST-001',
            'user_id' => $cashier->id,
            'shift_id' => $shift->id,
            'subtotal_usd' => 10.00,
            'discount_amount_usd' => 0,
            'tax_amount_usd' => 1.10,
            'total_usd' => 11.10,
            'total_lbp' => 1110000,
            'exchange_rate' => 100000,
            'payment_method' => Sale::METHOD_CASH_USD,
            'amount_tendered_usd' => 20,
            'change_usd' => 8.90,
            'change_lbp' => 0,
            'status' => Sale::STATUS_COMPLETED,
        ]);
        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => 'Test Item',
            'qty' => 2,
            'unit_price_usd' => 5,
            'cost_usd' => 2,
            'line_total_usd' => 10,
            'tax_rate' => 0.11,
            'tax_amount_usd' => 1.10,
        ]);
        return $sale->fresh('items');
    }

    public function test_cashier_can_print_their_own_receipt(): void
    {
        $cashier = User::factory()->create(['role' => User::ROLE_CASHIER]);
        $sale = $this->sampleSale($cashier);

        $this->actingAs($cashier)->get("/pos/receipts/{$sale->id}/print")
            ->assertOk()
            ->assertSee('REC-TEST-001')
            ->assertSee('Test Item');
    }

    public function test_cashier_cannot_print_someone_elses_receipt(): void
    {
        $cashier = User::factory()->create(['role' => User::ROLE_CASHIER]);
        $other = User::factory()->create(['role' => User::ROLE_CASHIER]);
        $sale = $this->sampleSale($other);

        $this->actingAs($cashier)->get("/pos/receipts/{$sale->id}/print")
            ->assertForbidden();
    }

    public function test_manager_can_print_any_receipt(): void
    {
        $manager = User::factory()->manager()->create();
        $cashier = User::factory()->create(['role' => User::ROLE_CASHIER]);
        $sale = $this->sampleSale($cashier);

        $this->actingAs($manager)->get("/pos/receipts/{$sale->id}/print")
            ->assertOk()
            ->assertSee('REC-TEST-001');
    }

    public function test_receipt_includes_business_info_from_settings(): void
    {
        Setting::set('business_name', 'My Test Shop', 'general');
        Setting::set('tax_number', 'LB-VAT-12345', 'general');
        Setting::set('receipt_footer', 'Come again soon!', 'receipt');

        $cashier = User::factory()->create(['role' => User::ROLE_CASHIER]);
        $sale = $this->sampleSale($cashier);

        $response = $this->actingAs($cashier)->get("/pos/receipts/{$sale->id}/print");
        $response->assertOk()
            ->assertSee('My Test Shop')
            ->assertSee('LB-VAT-12345')
            ->assertSee('Come again soon!');
    }

    public function test_receipt_shows_payment_method_and_change(): void
    {
        $cashier = User::factory()->create(['role' => User::ROLE_CASHIER]);
        $sale = $this->sampleSale($cashier);

        $response = $this->actingAs($cashier)->get("/pos/receipts/{$sale->id}/print");
        $response->assertSee('TOTAL')
            ->assertSee('$11.10')
            ->assertSee('Change USD')
            ->assertSee('$8.90');
    }

    public function test_auto_print_query_emits_script_tag(): void
    {
        $cashier = User::factory()->create(['role' => User::ROLE_CASHIER]);
        $sale = $this->sampleSale($cashier);

        // The button's onclick="window.print()" appears in either case; use the
        // auto-print listener as a marker (only present when auto=1).
        $this->actingAs($cashier)->get("/pos/receipts/{$sale->id}/print?auto=1")
            ->assertOk()
            ->assertSee('addEventListener', false);

        $this->actingAs($cashier)->get("/pos/receipts/{$sale->id}/print?auto=0")
            ->assertOk()
            ->assertDontSee('addEventListener', false);
    }

    public function test_reprint_works_after_shift_close(): void
    {
        $cashier = User::factory()->create(['role' => User::ROLE_CASHIER]);
        $sale = $this->sampleSale($cashier);
        Shift::where('user_id', $cashier->id)->update([
            'status' => Shift::STATUS_CLOSED,
            'closed_at' => now(),
        ]);

        $this->actingAs($cashier)->get("/pos/receipts/{$sale->id}/print")->assertOk();
    }
}
