<?php

namespace Tests\Feature\Pos;

use App\Models\AuditLog;
use App\Models\HeldSale;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Cashier price override: one line's unit price for the current sale only. The list price never
 * moves; the server re-derives everything and refuses what the user may not do.
 */
class PriceOverrideTest extends TestCase
{
    use RefreshDatabase;

    private function userWithShift(string $role = User::ROLE_CASHIER, array $attrs = []): User
    {
        $user = User::factory()->create($attrs + ['role' => $role]);
        Shift::create([
            'user_id' => $user->id, 'opened_at' => now(),
            'opening_cash_usd' => 0, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN,
        ]);

        return $user;
    }

    private function manager(array $attrs = []): User
    {
        return $this->userWithShift(User::ROLE_MANAGER, $attrs + ['pin' => '4321']);
    }

    /** Untaxed, so every expected number is the plain price. */
    private function product(array $attrs = []): Product
    {
        return Product::factory()->create($attrs + [
            'price_usd' => 10.00, 'wholesale_price_usd' => 8.00, 'cost_usd' => 3.00,
            'stock_qty' => 100, 'is_taxable' => false, 'allow_discount' => true,
        ]);
    }

    private function line(Product $product, ?float $price, array $extra = [], int $qty = 1): array
    {
        $line = ['product_id' => $product->id, 'qty' => $qty];
        if ($price !== null) {
            $line += ['unit_price' => $price, 'original_unit_price' => (float) $product->price_usd, 'price_reason' => 'Damaged box'];
        }

        return $extra + $line;
    }

    private function sell(User $user, array $line, array $extra = []): TestResponse
    {
        return $this->actingAs($user)->postJson('/pos/api/sales', $extra + [
            'cart' => [$line],
            'payment' => ['method' => 'cash_usd', 'amount_usd' => 1000],
        ]);
    }

    private function approval(User $cashier, Product $product, float $price, string $login, string $secret): TestResponse
    {
        return $this->actingAs($cashier)->postJson('/pos/api/price-approval', [
            'product_id' => $product->id, 'price' => $price, 'login' => $login, 'secret' => $secret,
        ]);
    }

    private function token(User $cashier, Product $product, float $price, ?User $manager = null): string
    {
        $manager ??= $this->manager();

        return $this->approval($cashier, $product, $price, $manager->username, '4321')->assertOk()->json('token');
    }

    private function item(TestResponse $response): SaleItem
    {
        return Sale::with('items')->findOrFail($response->assertCreated()->json('sale.id'))->items[0];
    }

    // ---- what a cashier may do alone ------------------------------------------------

    public function test_a_cut_within_the_limit_is_charged_with_vat_on_the_new_price_and_is_audited(): void
    {
        $vat = Tax::create(['name' => 'VAT', 'rate' => 0.11, 'is_inclusive' => false, 'is_default' => true, 'is_active' => true]);
        $product = $this->product(['tax_id' => $vat->id, 'is_taxable' => true]);
        $cashier = $this->userWithShift();

        $response = $this->sell($cashier, $this->line($product, 8.50, [], 3));

        $sale = Sale::with('items')->findOrFail($response->assertCreated()->json('sale.id'));
        $item = $sale->items[0];
        $this->assertEqualsWithDelta(8.50, (float) $item->unit_price_usd, 0.00001);
        $this->assertEqualsWithDelta(10.00, (float) $item->original_unit_price_usd, 0.00001);
        $this->assertSame($cashier->id, $item->price_overridden_by);
        $this->assertNull($item->price_override_approved_by);
        $this->assertSame('Damaged box', $item->price_override_reason);
        // 3 x 8.50 = 25.50, VAT 11% = 2.805, total 28.305 - all on the overridden price.
        $this->assertEqualsWithDelta(25.50, (float) $sale->subtotal_usd, 0.00001);
        $this->assertEqualsWithDelta(2.805, (float) $sale->tax_amount_usd, 0.00001);
        $this->assertEqualsWithDelta(28.305, (float) $sale->total_usd, 0.00001);

        // The catalog price never moves.
        $this->assertEqualsWithDelta(10.00, (float) $product->fresh()->price_usd, 0.00001);

        $audit = AuditLog::where('action', 'price_override')->firstOrFail();
        $this->assertSame($item->id, $audit->model_id);
        $this->assertEqualsWithDelta(10.0, $audit->old_values['unit_price'], 0.00001);
        $this->assertEqualsWithDelta(8.5, $audit->new_values['unit_price'], 0.00001);
        $this->assertEqualsWithDelta(-15.0, $audit->new_values['change_pct'], 0.0001);
        $this->assertSame($sale->id, $audit->new_values['sale_id']);
        $this->assertSame($cashier->id, $audit->new_values['cashier_id']);
        $this->assertNull($audit->new_values['approver_id']);
        $this->assertSame('Damaged box', $audit->new_values['reason']);
        $this->assertSame(1, AuditLog::where('action', 'sale')->firstOrFail()->new_values['price_overrides']);
    }

    public function test_a_cut_of_exactly_the_limit_passes_and_a_hair_more_needs_a_manager(): void
    {
        $product = $this->product();
        $cashier = $this->userWithShift();

        $this->sell($cashier, $this->line($product, 8.00))->assertCreated(); // exactly 20%

        $this->sell($cashier, $this->line($product, 7.999))                    // 20.01%
            ->assertStatus(422)->assertJsonStructure(['error' => ['cart.0.price']]);
        $this->assertSame(1, Sale::count());
    }

    public function test_the_limit_comes_from_the_setting(): void
    {
        Setting::set('price_override_max_decrease_pct', '5', 'pos', 'int');
        $product = $this->product();
        $cashier = $this->userWithShift();

        $this->sell($cashier, $this->line($product, 9.50))->assertCreated();
        $this->sell($cashier, $this->line($product, 9.40))->assertStatus(422);
    }

    public function test_raising_a_price_needs_no_approval_and_no_reason(): void
    {
        $product = $this->product();

        $response = $this->sell($this->userWithShift(), $this->line($product, 15.00, ['price_reason' => null]));

        $item = $this->item($response);
        $this->assertEqualsWithDelta(15.00, (float) $item->unit_price_usd, 0.00001);
        $this->assertEqualsWithDelta(10.00, (float) $item->original_unit_price_usd, 0.00001);
        $this->assertNull($item->price_override_reason);
        $this->assertEqualsWithDelta(15.00, (float) $response->json('sale.total_usd'), 0.00001);
    }

    public function test_a_reason_is_required_to_lower_a_price_unless_the_store_waives_it(): void
    {
        $product = $this->product();
        $cashier = $this->userWithShift();

        $this->sell($cashier, $this->line($product, 9.00, ['price_reason' => '  ']))
            ->assertStatus(422)->assertJsonStructure(['error' => ['cart.0.price']]);

        Setting::set('price_override_require_reason', '0', 'pos', 'bool');
        $this->sell($cashier, $this->line($product, 9.00, ['price_reason' => null]))->assertCreated();
    }

    public function test_a_line_at_the_list_price_is_not_an_override(): void
    {
        $product = $this->product();
        Setting::set('price_override_enabled', '0', 'pos', 'bool');

        $item = $this->item($this->sell($this->userWithShift(), $this->line($product, 10.00)));

        $this->assertNull($item->price_overridden_by);
        $this->assertEqualsWithDelta(10.00, (float) $item->original_unit_price_usd, 0.00001);
        $this->assertSame(0, AuditLog::where('action', 'price_override')->count());
    }

    // ---- what needs a manager -------------------------------------------------------

    public function test_a_cut_beyond_the_limit_without_an_approver_is_refused(): void
    {
        $product = $this->product();

        $this->sell($this->userWithShift(), $this->line($product, 5.00))
            ->assertStatus(422)->assertJsonStructure(['error' => ['cart.0.price']]);

        $this->assertSame(0, Sale::count());
        $this->assertSame('100.0000', (string) $product->fresh()->stock_qty);
    }

    public function test_a_manager_approval_lets_a_cashier_cut_beyond_the_limit(): void
    {
        $product = $this->product();
        $cashier = $this->userWithShift();
        $manager = $this->manager();

        $token = $this->token($cashier, $product, 5.00, $manager);
        $item = $this->item($this->sell($cashier, $this->line($product, 5.00, ['price_approval' => $token])));

        $this->assertEqualsWithDelta(5.00, (float) $item->unit_price_usd, 0.00001);
        $this->assertSame($cashier->id, $item->price_overridden_by);
        $this->assertSame($manager->id, $item->price_override_approved_by);

        $audit = AuditLog::where('action', 'price_override')->firstOrFail();
        $this->assertSame($manager->id, $audit->new_values['approver_id']);
        $this->assertTrue(AuditLog::where('action', 'price_approval')->where('user_id', $cashier->id)->exists());
    }

    public function test_a_cashier_cannot_stand_in_as_the_approver(): void
    {
        $product = $this->product();
        $cashier = $this->userWithShift();
        $colleague = $this->userWithShift(User::ROLE_CASHIER, ['pin' => '1111']);

        $this->approval($cashier, $product, 5.00, $colleague->username, '1111')
            ->assertStatus(422)->assertJsonValidationErrors('approver');
        $this->approval($cashier, $product, 5.00, $colleague->username, 'password')
            ->assertStatus(422)->assertJsonValidationErrors('approver');

        // And a made-up approval is no approval.
        $this->sell($cashier, $this->line($product, 5.00, ['price_approval' => 'forged']))->assertStatus(422);
        $this->assertSame(0, Sale::count());
    }

    public function test_the_approver_may_be_an_admin_and_may_sign_with_pin_password_or_email(): void
    {
        $product = $this->product();
        $cashier = $this->userWithShift();
        $admin = User::factory()->admin()->create(['pin' => '9999']);

        $this->approval($cashier, $product, 5.00, $admin->username, '9999')->assertOk()->assertJsonPath('approver.id', $admin->id);
        $this->approval($cashier, $product, 5.00, $admin->email, 'password')->assertOk();
    }

    public function test_an_inactive_manager_cannot_approve(): void
    {
        $manager = $this->manager(['is_active' => false]);

        $this->approval($this->userWithShift(), $this->product(), 5.00, $manager->username, '4321')->assertStatus(422);
    }

    public function test_an_approval_is_tied_to_one_cashier_one_product_and_one_price(): void
    {
        $product = $this->product();
        $other = $this->product();
        $cashier = $this->userWithShift();
        $token = $this->token($cashier, $product, 5.00);

        $this->sell($cashier, $this->line($product, 4.00, ['price_approval' => $token]))->assertStatus(422); // other price
        $this->sell($cashier, $this->line($other, 5.00, ['price_approval' => $token]))->assertStatus(422);   // other product
        $this->sell($this->userWithShift(), $this->line($product, 5.00, ['price_approval' => $token]))->assertStatus(422); // other cashier
        $this->assertSame(0, Sale::count());

        $this->sell($cashier, $this->line($product, 5.00, ['price_approval' => $token]))->assertCreated();
    }

    public function test_an_approval_can_be_used_once(): void
    {
        $product = $this->product();
        $cashier = $this->userWithShift();
        $token = $this->token($cashier, $product, 5.00);

        $this->sell($cashier, $this->line($product, 5.00, ['price_approval' => $token]))->assertCreated();
        $this->sell($cashier, $this->line($product, 5.00, ['price_approval' => $token]))
            ->assertStatus(422)->assertJsonStructure(['error' => ['cart.0.price']]);
        $this->assertSame(1, Sale::count());
    }

    public function test_an_expired_approval_is_refused(): void
    {
        $product = $this->product();
        $cashier = $this->userWithShift();
        $token = $this->token($cashier, $product, 5.00);

        $this->travel(5)->hours();
        $this->sell($cashier, $this->line($product, 5.00, ['price_approval' => $token]))->assertStatus(422);
    }

    public function test_an_approval_is_void_once_the_manager_is_deactivated(): void
    {
        $product = $this->product();
        $cashier = $this->userWithShift();
        $manager = $this->manager();
        $token = $this->token($cashier, $product, 5.00, $manager);

        $manager->update(['is_active' => false]);

        $this->sell($cashier, $this->line($product, 5.00, ['price_approval' => $token]))->assertStatus(422);
    }

    public function test_wrong_manager_credentials_lock_after_five_attempts(): void
    {
        $product = $this->product();
        $cashier = $this->userWithShift();
        $manager = $this->manager();

        for ($i = 0; $i < 5; $i++) {
            $this->approval($cashier, $product, 5.00, $manager->username, '0000')->assertStatus(422);
        }

        // Even the right PIN is refused now.
        $this->approval($cashier, $product, 5.00, $manager->username, '4321')
            ->assertStatus(422)->assertJsonValidationErrors('approver');
    }

    public function test_managers_are_not_limited_and_need_no_approval(): void
    {
        $manager = $this->manager();
        $product = $this->product();

        $item = $this->item($this->sell($manager, $this->line($product, 1.00)));

        $this->assertEqualsWithDelta(1.00, (float) $item->unit_price_usd, 0.00001);
        $this->assertSame($manager->id, $item->price_overridden_by);
        $this->assertNull($item->price_override_approved_by);
    }

    // ---- tampering and switches ------------------------------------------------------

    public function test_a_changed_price_is_refused_while_overrides_are_switched_off(): void
    {
        $product = $this->product();
        Setting::set('price_override_enabled', '0', 'pos', 'bool');

        foreach ([$this->userWithShift(), $this->manager()] as $user) {
            $this->sell($user, $this->line($product, 9.00))
                ->assertStatus(422)->assertJsonStructure(['error' => ['cart.0.price']]);
        }
        $this->actingAs($this->userWithShift())->postJson('/pos/api/price-approval', [
            'product_id' => $product->id, 'price' => 5, 'login' => 'x', 'secret' => 'y',
        ])->assertForbidden();
        $this->assertSame(0, Sale::count());
    }

    public function test_a_price_that_is_not_a_positive_number_is_refused(): void
    {
        $product = $this->product();
        $cashier = $this->userWithShift();

        foreach ([0, -3, 'abc'] as $bad) {
            $this->sell($cashier, ['product_id' => $product->id, 'qty' => 1, 'unit_price' => $bad])
                ->assertStatus(422)->assertJsonValidationErrors('cart.0.unit_price');
        }
        $this->assertSame(0, Sale::count());
    }

    public function test_the_price_the_cashier_saw_must_still_be_the_list_price(): void
    {
        $product = $this->product();

        $this->sell($this->userWithShift(), $this->line($product, 9.00, ['original_unit_price' => 12.00]))
            ->assertStatus(422)->assertJsonStructure(['error' => ['cart.0.price']]);
    }

    public function test_a_product_that_refuses_discounts_cannot_be_marked_down_but_can_be_raised(): void
    {
        $product = $this->product(['allow_discount' => false]);
        $cashier = $this->userWithShift();

        $this->sell($cashier, $this->line($product, 9.00))->assertStatus(422);
        $this->sell($cashier, $this->line($product, 11.00))->assertCreated();
    }

    public function test_the_override_is_measured_against_the_customers_price_list(): void
    {
        $product = $this->product();
        $cashier = $this->userWithShift();

        // Wholesale list is 8.00: 6.50 is a cut of 18.75% and 8.00 -> 6.00 would be 25%.
        $ok = $this->line($product, 6.50, ['original_unit_price' => 8.00]);
        $item = $this->item($this->sell($cashier, $ok, ['price_tier' => 'wholesale']));
        $this->assertEqualsWithDelta(8.00, (float) $item->original_unit_price_usd, 0.00001);

        $this->sell($cashier, $this->line($product, 6.00, ['original_unit_price' => 8.00]), ['price_tier' => 'wholesale'])
            ->assertStatus(422);
    }

    public function test_a_line_discount_applies_on_top_of_the_overridden_price(): void
    {
        $product = $this->product();

        $response = $this->sell($this->userWithShift(), $this->line($product, 8.00, ['discount_pct' => 10], 2));

        $this->assertEqualsWithDelta(14.40, (float) $response->assertCreated()->json('sale.total_usd'), 0.00001); // 2 x 8.00 less 10%
    }

    // ---- hold / recall ---------------------------------------------------------------

    public function test_a_held_cart_keeps_its_override_and_the_sale_is_still_re_checked(): void
    {
        $product = $this->product();
        $cashier = $this->userWithShift();
        $line = $this->line($product, 8.00, ['price_approval' => null], 2);

        $holdId = $this->actingAs($cashier)->postJson('/pos/api/holds', ['cart' => [$line]])->assertCreated()->json('id');

        $list = $this->actingAs($cashier)->getJson('/pos/api/holds')->assertOk()->json();
        $this->assertEqualsWithDelta(16.00, (float) $list[0]['subtotal'], 0.001);

        $recalled = $this->actingAs($cashier)->postJson("/pos/api/holds/{$holdId}/recall")->assertOk()
            ->assertJsonPath('cart.0.unit_price', 8)
            ->assertJsonPath('cart.0.price_reason', 'Damaged box')
            ->json('cart.0');

        $this->assertSame(0, HeldSale::count()); // recalling consumes the hold
        $this->sell($cashier, $recalled)->assertCreated();
    }

    // ---- returns, receipts, reports --------------------------------------------------

    public function test_a_return_refunds_the_charged_price_not_the_list_price(): void
    {
        $product = $this->product();
        $manager = $this->manager();
        $saleId = $this->sell($manager, $this->line($product, 8.00, [], 2))->assertCreated()->json('sale.id');
        $item = SaleItem::where('sale_id', $saleId)->firstOrFail();

        $this->actingAs($manager)->post(route('returns.store', Sale::findOrFail($saleId)), [
            'items' => [['sale_item_id' => $item->id, 'qty_returned' => 1, 'restock' => 1]],
            'reason' => 'defective', 'refund_method' => 'cash_usd',
        ])->assertRedirect();

        $this->assertEqualsWithDelta(8.00, (float) \App\Models\ReturnTransaction::firstOrFail()->refund_amount_usd, 0.00001);
    }

    public function test_the_thermal_receipt_shows_the_list_price_struck_through(): void
    {
        $product = $this->product();
        $manager = $this->manager();
        $overridden = $this->sell($manager, $this->line($product, 8.00))->assertCreated()->json('sale.id');
        $plain = $this->sell($manager, $this->line($product, null))->assertCreated()->json('sale.id');

        $this->actingAs($manager)->get("/pos/receipts/{$overridden}/print")
            ->assertOk()->assertSee('× $8.00', false)->assertSee('<s><bdi dir="ltr">$10.00</bdi></s>', false);
        $this->actingAs($manager)->get("/pos/receipts/{$plain}/print")->assertOk()->assertDontSee('<s>', false);
    }

    public function test_the_a4_invoice_shows_the_list_price_struck_through(): void
    {
        $product = $this->product();
        $manager = $this->manager();
        $overridden = $this->sell($manager, $this->line($product, 8.00))->assertCreated()->json('sale.id');

        $this->actingAs($manager)->get("/pos/receipts/{$overridden}/print?format=a4")
            ->assertOk()->assertSee('<s class="muted">$10.00</s>', false);
    }

    public function test_a_store_can_keep_the_list_price_off_its_receipts(): void
    {
        Setting::set('price_override_show_original', '0', 'pos', 'bool');
        $product = $this->product();
        $manager = $this->manager();
        $saleId = $this->sell($manager, $this->line($product, 8.00))->assertCreated()->json('sale.id');

        $this->actingAs($manager)->get("/pos/receipts/{$saleId}/print")
            ->assertOk()->assertSee('× $8.00', false)->assertDontSee('$10.00')->assertDontSee('<s>', false);
        $this->actingAs($manager)->get("/pos/receipts/{$saleId}/print?format=a4")
            ->assertOk()->assertDontSee('$10.00')->assertDontSee('<s class="muted">', false);

        // Only the printout is affected: the sale line and the report still carry the list price.
        $this->assertEqualsWithDelta(10.00, (float) SaleItem::firstOrFail()->original_unit_price_usd, 0.00001);
        $this->actingAs($manager)->get(route('reports.price-overrides'))->assertOk()->assertSee('$10.00');
    }

    public function test_the_price_overrides_report_lists_lines_and_nets_the_difference(): void
    {
        $product = $this->product();
        $cashier = $this->userWithShift(User::ROLE_CASHIER, ['name' => 'Rita Cashier']);
        $manager = $this->manager(['name' => 'Omar Manager']);

        $this->sell($cashier, $this->line($product, 9.00, ['price_reason' => 'Loyal customer'], 2))->assertCreated();            // -2.00
        $this->sell($cashier, $this->line($product, 5.00, ['price_approval' => $this->token($cashier, $product, 5.00, $manager)], 1))->assertCreated(); // -5.00
        $this->sell($cashier, $this->line($product, 12.00, ['price_reason' => null], 1))->assertCreated();                       // +2.00
        $voided = $this->sell($cashier, $this->line($product, 9.00))->assertCreated()->json('sale.id');
        app(\App\Services\SaleService::class)->voidSale(Sale::findOrFail($voided), $manager->id, 'test');
        $this->sell($cashier, $this->line($product, null))->assertCreated();                                                      // not an override

        $this->actingAs($manager)->get(route('reports.price-overrides'))
            ->assertOk()
            ->assertSee('Rita Cashier')->assertSee('Omar Manager')->assertSee('Loyal customer')
            ->assertSee('-$5.00')->assertSee('-$2.00')->assertSee('+$2.00');

        $rows = app(\App\Services\ReportService::class)->priceOverrides(now()->startOfDay(), now()->endOfDay());
        $this->assertSame(3, $rows['totals']['count']);
        $this->assertEqualsWithDelta(-5.00, $rows['totals']['net_difference'], 0.00001);

        $this->actingAs($manager)->get(route('reports.price-overrides', ['format' => 'pdf']))->assertOk();
    }

    public function test_only_managers_and_admins_can_open_the_price_overrides_report(): void
    {
        $this->actingAs($this->userWithShift())->get(route('reports.price-overrides'))->assertForbidden();
        $this->actingAs($this->userWithShift(User::ROLE_STOCK))->get(route('reports.price-overrides'))->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get(route('reports.price-overrides'))->assertOk();
    }

    // ---- sell screen and settings ----------------------------------------------------

    public function test_the_sell_screen_is_told_the_users_limit(): void
    {
        $this->withoutVite();

        $this->actingAs($this->userWithShift())->get('/pos')
            ->assertOk()->assertSee('"enabled":true,"limit":20', false)->assertSee('"requireReason":true', false);
        $this->actingAs($this->manager())->get('/pos')->assertOk()->assertSee('"limit":100', false);

        Setting::set('price_override_enabled', '0', 'pos', 'bool');
        $this->actingAs($this->userWithShift())->get('/pos')->assertOk()->assertSee('"enabled":false', false);
    }

    public function test_an_admin_saves_the_three_settings_and_the_limit_is_range_checked(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('settings.update'), [
            'group' => 'pos', 'settings' => ['price_override_max_decrease_pct' => 35],
        ])->assertRedirect();
        $this->assertFalse((bool) Setting::get('price_override_enabled', true));      // unchecked box
        $this->assertFalse((bool) Setting::get('price_override_require_reason', true));
        $this->assertFalse((bool) Setting::get('price_override_show_original', true));
        $this->assertSame(35, Setting::get('price_override_max_decrease_pct'));

        $this->actingAs($admin)->post(route('settings.update'), [
            'group' => 'pos',
            'settings' => ['price_override_enabled' => '1', 'price_override_require_reason' => '1', 'price_override_show_original' => '1', 'price_override_max_decrease_pct' => 20],
        ])->assertRedirect();
        $this->assertTrue((bool) Setting::get('price_override_enabled', false));
        $this->assertTrue((bool) Setting::get('price_override_show_original', false));

        $this->actingAs($admin)->post(route('settings.update'), [
            'group' => 'pos', 'settings' => ['price_override_max_decrease_pct' => 101],
        ])->assertSessionHasErrors('settings.price_override_max_decrease_pct');
        $this->assertSame(20, Setting::get('price_override_max_decrease_pct'));
    }

    public function test_the_defaults_with_nothing_stored_are_on_20_percent_reason_required(): void
    {
        $svc = app(\App\Services\PriceOverrideService::class);

        $this->assertTrue($svc->enabled());
        $this->assertSame(20.0, $svc->maxDecreasePct());
        $this->assertTrue($svc->requireReason());
        $this->assertTrue($svc->showOriginalOnReceipt());
    }

    // ---- migration -------------------------------------------------------------------

    public function test_the_migration_backfills_the_original_price_of_existing_rows(): void
    {
        $path = 'database/migrations/2026_10_17_000001_add_price_override_to_sale_items.php';
        $sale = Sale::create([
            'receipt_number' => 'OLD-1', 'user_id' => $this->userWithShift()->id, 'shift_id' => Shift::first()->id,
            'subtotal_usd' => 7.5, 'total_usd' => 7.5, 'total_lbp' => 0, 'exchange_rate' => 90000,
            'payment_method' => 'cash_usd', 'status' => Sale::STATUS_COMPLETED,
        ]);
        $product = $this->product();

        Artisan::call('migrate:rollback', ['--path' => $path, '--force' => true]);
        DB::table('sale_items')->insert([
            'sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => 'Old', 'qty' => 1,
            'unit_price_usd' => 7.5, 'line_total_usd' => 7.5, 'created_at' => now(), 'updated_at' => now(),
        ]);
        Artisan::call('migrate', ['--path' => $path, '--force' => true]);

        $item = SaleItem::firstOrFail();
        $this->assertEqualsWithDelta(7.5, (float) $item->original_unit_price_usd, 0.00001);
        $this->assertFalse($item->isPriceOverridden());
    }
}
