<?php

namespace Tests\Feature\Pos;

use App\Models\Customer;
use App\Models\HeldSale;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The sell-screen customer picker: search, quick-add, and — the part that was
 * actually broken — customer_id surviving all the way onto the Sale row.
 */
class CustomerPickerTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;
    private Shift $shift;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $vat = Tax::create([
            'name' => 'Lebanese VAT', 'rate' => 0.11, 'is_inclusive' => false,
            'is_default' => true, 'is_active' => true,
        ]);

        $this->cashier = User::factory()->create(['role' => User::ROLE_CASHIER]);
        $this->shift = Shift::create([
            'user_id' => $this->cashier->id, 'opened_at' => now(),
            'opening_cash_usd' => 0, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN,
        ]);

        $this->product = Product::factory()->create([
            'name' => 'Rice 1kg', 'price_usd' => 10.00, 'cost_usd' => 4.00,
            'stock_qty' => 100, 'tax_id' => $vat->id, 'is_taxable' => true,
        ]);
    }

    private function customer(array $attrs = []): Customer
    {
        return Customer::create(array_merge([
            'name' => 'Nadia Khoury',
            'phone' => '03123456',
            'customer_group' => Customer::GROUP_RETAIL,
            'credit_limit' => 0,
            'balance' => 0,
            'loyalty_points' => 0,
            'is_active' => true,
        ], $attrs));
    }

    /** 1 x Rice @ 10.00 + 11% VAT = 11.10 */
    private function sellTo(?Customer $customer, array $payment = null): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->cashier)->postJson('/pos/api/sales', [
            'cart' => [['product_id' => $this->product->id, 'qty' => 1, 'unit_price' => 10.00]],
            'payment' => $payment ?? ['method' => 'cash_usd', 'amount_usd' => 11.10],
            'customer_id' => $customer?->id,
        ]);
    }

    // --- the actual functional fix ------------------------------------

    public function test_a_sale_persists_the_selected_customer(): void
    {
        $customer = $this->customer();

        $saleId = $this->sellTo($customer)->assertCreated()->json('sale.id');

        // Read it back from the database, not from the response the UI showed.
        $this->assertDatabaseHas('sales', ['id' => $saleId, 'customer_id' => $customer->id]);
        $this->assertSame($customer->id, Sale::findOrFail($saleId)->customer_id);

        // SaleController@show returns the Sale at the top level, customer loaded.
        $this->actingAs($this->cashier)->getJson("/pos/api/sales/{$saleId}")
            ->assertOk()
            ->assertJsonPath('customer_id', $customer->id)
            ->assertJsonPath('customer.name', 'Nadia Khoury');
    }

    public function test_a_sale_without_a_customer_still_works(): void
    {
        $saleId = $this->sellTo(null)->assertCreated()->json('sale.id');

        $this->assertNull(Sale::findOrFail($saleId)->customer_id);
    }

    public function test_an_unknown_customer_id_is_rejected(): void
    {
        $this->actingAs($this->cashier)->postJson('/pos/api/sales', [
            'cart' => [['product_id' => $this->product->id, 'qty' => 1, 'unit_price' => 10.00]],
            'payment' => ['method' => 'cash_usd', 'amount_usd' => 11.10],
            'customer_id' => 99999,
        ])->assertStatus(422);

        $this->assertSame(0, Sale::count());
    }

    // --- credit sales: SaleService's guards must be untouched ----------

    public function test_a_credit_sale_without_a_customer_is_rejected(): void
    {
        $this->sellTo(null, ['method' => 'credit', 'amount_credit' => 11.10])
            ->assertStatus(422)
            ->assertJsonPath('error.customer.0', 'A customer is required for credit sales.');

        $this->assertSame(0, Sale::count());
    }

    public function test_a_credit_sale_beyond_the_credit_limit_is_rejected(): void
    {
        $customer = $this->customer(['credit_limit' => 5.00, 'balance' => 0]);

        $this->sellTo($customer, ['method' => 'credit', 'amount_credit' => 11.10])
            ->assertStatus(422)
            ->assertJsonPath('error.customer.0', 'Customer credit limit exceeded.');

        $this->assertSame(0, Sale::count());
        $this->assertEqualsWithDelta(0.0, (float) $customer->fresh()->balance, 0.0001);
    }

    public function test_a_credit_sale_within_the_limit_charges_the_customer_balance(): void
    {
        $customer = $this->customer(['credit_limit' => 50.00, 'balance' => 0]);

        $saleId = $this->sellTo($customer, ['method' => 'credit', 'amount_credit' => 11.10])
            ->assertCreated()->json('sale.id');

        $this->assertSame($customer->id, Sale::findOrFail($saleId)->customer_id);
        $this->assertEqualsWithDelta(11.10, (float) $customer->fresh()->balance, 0.0001);
    }

    // --- search / quick-add endpoints in the shape the picker expects --

    public function test_search_finds_customers_by_name_and_phone(): void
    {
        $this->customer(['name' => 'Nadia Khoury', 'phone' => '03123456']);
        $this->customer(['name' => 'Rami Aoun', 'phone' => '71999888']);

        $byName = $this->actingAs($this->cashier)->getJson('/pos/api/customers/search?q=Nadia')->assertOk();
        $byName->assertJsonCount(1)->assertJsonPath('0.name', 'Nadia Khoury');

        $byPhone = $this->actingAs($this->cashier)->getJson('/pos/api/customers/search?q=71999')->assertOk();
        $byPhone->assertJsonCount(1)->assertJsonPath('0.name', 'Rami Aoun');

        // Everything the chip renders must be present.
        foreach (['id', 'name', 'phone', 'customer_group', 'balance', 'loyalty_points', 'tax_exempt'] as $field) {
            $byName->assertJsonStructure([['*' => []], 0 => [$field]]);
        }

        // Under two characters is not a search.
        $this->actingAs($this->cashier)->getJson('/pos/api/customers/search?q=N')
            ->assertOk()->assertJsonCount(0);
    }

    public function test_quick_add_creates_a_customer_and_returns_the_chip_shape(): void
    {
        $response = $this->actingAs($this->cashier)->postJson('/pos/api/customers/quick-add', [
            'name' => 'Walk-in Wholesaler',
            'phone' => '03 111 222',
            'customer_group' => 'wholesale',
        ])->assertCreated();

        $response->assertJsonStructure(['id', 'name', 'phone', 'customer_group', 'balance', 'loyalty_points', 'tax_exempt']);
        $response->assertJsonPath('customer_group', 'wholesale');

        // Column defaults must be real values, not the nulls an unrefreshed
        // model would hand back — the chip and any later credit check read them.
        $this->assertNotNull($response->json('balance'));
        $this->assertNotNull($response->json('loyalty_points'));
        $this->assertNotNull($response->json('credit_limit'));
        $this->assertFalse($response->json('tax_exempt'));

        $customer = Customer::where('name', 'Walk-in Wholesaler')->firstOrFail();
        $this->assertTrue($customer->is_active);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'create',
            'model_type' => Customer::class,
            'model_id' => $customer->id,
        ]);

        // And the id it hands back is usable on a sale straight away.
        $saleId = $this->sellTo($customer)->assertCreated()->json('sale.id');
        $this->assertSame($customer->id, Sale::findOrFail($saleId)->customer_id);
    }

    public function test_quick_add_rejects_a_malformed_phone(): void
    {
        $this->actingAs($this->cashier)->postJson('/pos/api/customers/quick-add', [
            'name' => 'Bad Number',
            'phone' => 'not-a-phone',
        ])->assertStatus(422)->assertJsonValidationErrors('phone');

        $this->assertSame(0, Customer::count());
    }

    // --- hold / recall round trip --------------------------------------

    public function test_recalling_a_hold_returns_the_full_customer_not_just_an_id(): void
    {
        $customer = $this->customer(['balance' => 12.50, 'loyalty_points' => 40]);

        $holdId = $this->actingAs($this->cashier)->postJson('/pos/api/holds', [
            'cart' => [['product_id' => $this->product->id, 'qty' => 2, 'unit_price' => 10.00]],
            'customer_id' => $customer->id,
            'label' => 'Table 5',
        ])->assertCreated()->json('id');

        $this->actingAs($this->cashier)->postJson("/pos/api/holds/{$holdId}/recall")
            ->assertOk()
            ->assertJsonPath('customer_id', $customer->id)
            ->assertJsonPath('customer.id', $customer->id)
            ->assertJsonPath('customer.name', 'Nadia Khoury')
            ->assertJsonPath('customer.phone', '03123456')
            ->assertJsonPath('customer.customer_group', 'retail')
            ->assertJsonPath('customer.loyalty_points', 40)
            ->assertJsonStructure(['customer' => ['id', 'name', 'phone', 'customer_group', 'balance', 'loyalty_points', 'tax_exempt']]);
    }

    public function test_recalling_a_hold_with_no_customer_returns_null(): void
    {
        $holdId = $this->actingAs($this->cashier)->postJson('/pos/api/holds', [
            'cart' => [['product_id' => $this->product->id, 'qty' => 1, 'unit_price' => 10.00]],
        ])->assertCreated()->json('id');

        $this->actingAs($this->cashier)->postJson("/pos/api/holds/{$holdId}/recall")
            ->assertOk()
            ->assertJsonPath('customer_id', null)
            ->assertJsonPath('customer', null);
    }

    public function test_a_held_customer_survives_the_round_trip_onto_the_sale(): void
    {
        $customer = $this->customer();

        $holdId = $this->actingAs($this->cashier)->postJson('/pos/api/holds', [
            'cart' => [['product_id' => $this->product->id, 'qty' => 1, 'unit_price' => 10.00]],
            'customer_id' => $customer->id,
        ])->assertCreated()->json('id');

        $recalled = $this->actingAs($this->cashier)->postJson("/pos/api/holds/{$holdId}/recall")->assertOk();
        $this->assertSame(0, HeldSale::count());

        // What the frontend would then send back from the restored chip.
        $saleId = $this->actingAs($this->cashier)->postJson('/pos/api/sales', [
            'cart' => [['product_id' => $this->product->id, 'qty' => 1, 'unit_price' => 10.00]],
            'payment' => ['method' => 'cash_usd', 'amount_usd' => 11.10],
            'customer_id' => $recalled->json('customer.id'),
        ])->assertCreated()->json('sale.id');

        $this->assertSame($customer->id, Sale::findOrFail($saleId)->customer_id);
    }

    // --- the screen itself ---------------------------------------------

    public function test_sell_screen_ships_the_customer_picker(): void
    {
        $this->actingAs($this->cashier)->get('/pos')
            ->assertOk()
            ->assertSee('+ Add customer', false)
            ->assertSee('+ New customer', false)
            ->assertSee('openCustomerModal()', false)
            ->assertSee('/pos/api/customers/search?q=', false)
            ->assertSee('/pos/api/customers/quick-add', false);

        // The fix itself: the /pos/api/sales body carries customer_id. Asserting
        // the bare line would pass on the old code too — submitHold() already
        // had it — so anchor it to the payment key that only submit() sends.
        $this->assertMatchesRegularExpression(
            '/payment:\s*this\.payment,\s*customer_id:\s*this\.customer\?\.id \|\| null,/',
            $this->actingAs($this->cashier)->get('/pos')->getContent()
        );
    }
}
