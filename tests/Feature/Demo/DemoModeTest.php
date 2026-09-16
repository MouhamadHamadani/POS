<?php

namespace Tests\Feature\Demo;

use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a demo build must and must not do. The point of a demo is that it looks
 * exactly like production everywhere except the places that would let it be
 * mistaken for a client's live till — so these pin both halves: the banner and
 * watermark are always there, and the side-effecting screens are refused.
 */
class DemoModeTest extends TestCase
{
    use RefreshDatabase;

    private function demo(bool $on = true): void
    {
        config(['pos.demo_mode' => $on]);
    }

    private function shiftFor(User $user): Shift
    {
        return Shift::create([
            'user_id' => $user->id,
            'opened_at' => now(),
            'opening_cash_usd' => 0, 'opening_cash_lbp' => 0,
            'status' => Shift::STATUS_OPEN,
        ]);
    }

    private function sale(User $cashier): Sale
    {
        $shift = $this->shiftFor($cashier);
        $sale = Sale::create([
            'receipt_number' => 'REC-DEMO-001',
            'user_id' => $cashier->id,
            'shift_id' => $shift->id,
            'subtotal_usd' => 10.00,
            'discount_amount_usd' => 0,
            'tax_amount_usd' => 1.10,
            'total_usd' => 11.10,
            'total_lbp' => 999000,
            'exchange_rate' => 90000,
            'payment_method' => Sale::METHOD_CASH_USD,
            'amount_tendered_usd' => 20,
            'change_usd' => 8.90,
            'change_lbp' => 0,
            'status' => Sale::STATUS_COMPLETED,
        ]);
        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => \App\Models\Product::factory()->create()->id,
            'product_name' => 'Demo Item',
            'qty' => 2,
            'unit_price_usd' => 5,
            'cost_usd' => 2,
            'line_total_usd' => 10,
            'tax_rate' => 0.11,
            'tax_amount_usd' => 1.10,
        ]);

        return $sale->fresh('items');
    }

    // === The banner: every layout, both languages ===

    public function test_the_banner_is_on_the_login_screen(): void
    {
        $this->demo();
        User::factory()->admin()->create(); // otherwise every route funnels to /setup

        $this->get('/login')
            ->assertOk()
            ->assertSee('DEMO MODE')
            ->assertSee('وضع العرض التجريبي', false);
    }

    public function test_the_banner_is_on_the_management_screens(): void
    {
        $this->demo();
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertSee('DEMO MODE')
            ->assertSee('وضع العرض التجريبي', false);
    }

    public function test_the_banner_is_on_the_till_screen_in_both_languages(): void
    {
        $this->demo();

        foreach (['en', 'ar'] as $language) {
            $user = User::factory()->create(['language' => $language]);
            $this->shiftFor($user);

            $this->actingAs($user)->get('/pos')
                ->assertOk()
                ->assertSee('DEMO MODE')
                ->assertSee('وضع العرض التجريبي', false);
        }
    }

    public function test_a_production_build_shows_no_banner_anywhere(): void
    {
        $this->demo(false);
        $user = User::factory()->admin()->create();

        // Guest screen first — an authenticated session is redirected off /login.
        $this->get('/login')->assertOk()->assertDontSee('DEMO MODE');
        $this->actingAs($user)->get('/dashboard')->assertOk()->assertDontSee('DEMO MODE');
    }

    public function test_the_login_screen_lists_the_demo_accounts_and_password(): void
    {
        $this->demo();
        User::factory()->admin()->create();

        $response = $this->get('/login')->assertOk();

        foreach (['demo_admin', 'demo_manager', 'demo_cashier', 'demo_stock'] as $username) {
            $response->assertSee($username);
        }

        $response->assertSee(config('pos.demo_password'));
    }

    public function test_a_production_login_screen_lists_no_credentials(): void
    {
        $this->demo(false);
        User::factory()->admin()->create();

        $this->get('/login')->assertOk()->assertDontSee('demo_admin');
    }

    // === Receipts: printable, but never passable as a real one ===

    public function test_a_demo_receipt_is_watermarked_in_both_languages(): void
    {
        $this->demo();
        $cashier = User::factory()->create();
        $sale = $this->sale($cashier);

        $this->actingAs($cashier)->get("/pos/receipts/{$sale->id}/print")
            ->assertOk()
            ->assertSee('DEMO RECEIPT')
            ->assertSee('NOT A VALID RECEIPT')
            ->assertSee('إيصال تجريبي — غير صالح', false);
    }

    public function test_a_production_receipt_carries_no_watermark(): void
    {
        $this->demo(false);
        $cashier = User::factory()->create();
        $sale = $this->sale($cashier);

        $this->actingAs($cashier)->get("/pos/receipts/{$sale->id}/print")
            ->assertOk()
            ->assertDontSee('DEMO RECEIPT');
    }

    // === Nothing with a real-world side effect ===

    public function test_backups_are_refused_in_a_demo_build(): void
    {
        $this->demo();
        // A demo seeds no super_admin at all; this proves the route would still
        // refuse if one somehow existed.
        $owner = User::factory()->superAdmin()->create();

        $this->actingAs($owner)->post('/settings/backup/now')
            ->assertRedirect()
            ->assertSessionHasErrors('demo');

        $this->actingAs($owner)->post('/settings/backup/restore/whatever.sqlite')
            ->assertSessionHasErrors('demo');
    }

    public function test_report_exports_are_refused_but_the_report_still_renders(): void
    {
        $this->demo();
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)->get('/reports/sales/daily?format=pdf')
            ->assertRedirect()
            ->assertSessionHasErrors('demo');

        $this->actingAs($manager)->get('/reports/sales/daily?format=xlsx')
            ->assertSessionHasErrors('demo');

        $this->actingAs($manager)->get('/reports/sales/daily')
            ->assertOk()
            ->assertSee('Export disabled in demo');
    }

    public function test_exports_still_work_in_a_production_build(): void
    {
        $this->demo(false);
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)->get('/reports/sales/daily?format=xlsx')
            ->assertOk()
            ->assertSessionHasNoErrors();
    }

    // === The baseline stays the baseline ===

    public function test_business_identity_and_currency_settings_are_locked(): void
    {
        $this->demo();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post('/settings', ['group' => 'general', 'settings' => ['business_name' => 'Somebody Real']])
            ->assertSessionHasErrors('demo');

        $this->actingAs($admin)
            ->post('/settings', ['group' => 'currency', 'settings' => ['exchange_rate' => '12345']])
            ->assertSessionHasErrors('demo');

        $this->assertNotSame('Somebody Real', \App\Models\Setting::get('business_name'));
        $this->assertNotSame(12345.0, (float) \App\Models\Setting::get('exchange_rate'));
    }

    public function test_vat_cannot_be_edited_in_a_demo_build(): void
    {
        $this->demo();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post('/settings/tax', ['name' => 'Invented VAT', 'rate' => 0.25])
            ->assertSessionHasErrors('demo');

        $this->assertDatabaseMissing('taxes', ['name' => 'Invented VAT']);
    }

    public function test_the_locked_tabs_are_not_offered_in_a_demo_build(): void
    {
        $this->demo();
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get('/settings')->assertOk();

        $response->assertDontSee('?tab=currency', false);
        $response->assertDontSee('?tab=tax', false);
        $response->assertSee('?tab=receipt', false);
    }

    public function test_pos_behaviour_settings_still_save_in_a_demo_build(): void
    {
        $this->demo();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post('/settings', ['group' => 'pos', 'settings' => ['idle_timeout_min' => '15']])
            ->assertSessionHasNoErrors();

        $this->assertSame(15, (int) \App\Models\Setting::get('idle_timeout_min'));
    }

    // === The reset endpoint only exists in a demo build ===

    public function test_the_reset_endpoint_does_not_exist_in_a_production_build(): void
    {
        $this->demo(false);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/demo/reset')->assertNotFound();
    }
}
