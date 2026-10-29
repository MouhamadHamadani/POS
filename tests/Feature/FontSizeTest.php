<?php

namespace Tests\Feature;

use App\Models\PrintTemplate;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\Tax;
use App\Models\User;
use App\Services\ReceiptPrintService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Two size options: the receipt text size (install-wide, Settings -> Receipt) and the interface
 * size (each user's own, "My preferences").
 */
class FontSizeTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;
    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('exchange_rate', 90000, 'currency', 'float');
        $this->cashier = User::factory()->create(['max_discount_pct' => 100]);
        $this->shift = Shift::create([
            'user_id' => $this->cashier->id, 'opened_at' => now(),
            'opening_cash_usd' => 0, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN,
        ]);
    }

    private function sale(): Sale
    {
        $vat = Tax::firstOrCreate(['name' => 'Lebanese VAT'], ['rate' => 0.11, 'is_inclusive' => false, 'is_default' => true, 'is_active' => true]);
        $product = Product::factory()->create(['name' => 'Test Item', 'name_ar' => 'صنف', 'price_usd' => 5, 'stock_qty' => 10, 'tax_id' => $vat->id, 'is_taxable' => true]);

        return app(SaleService::class)->process(
            cart: [['product_id' => $product->id, 'qty' => 2]],
            payment: ['method' => 'cash_usd', 'amount_usd' => 20.00],
            user: $this->cashier, shiftId: $this->shift->id,
        );
    }

    /** @return array<string, array{string, string}> */
    public static function sizes(): array
    {
        return ['small' => ['small', '0.9'], 'normal' => ['normal', '1'], 'large' => ['large', '1.2']];
    }

    // =========================== A) receipt text size ===========================

    public function test_the_default_is_normal(): void
    {
        $this->seed(\Database\Seeders\DefaultSettingsSeeder::class);

        $this->assertSame('normal', Setting::get('receipt_font_size'));
        $this->assertSame(1.0, app(ReceiptPrintService::class)->fontScale());
    }

    #[DataProvider('sizes')]
    public function test_the_small_receipt_and_the_a4_invoice_scale_with_the_setting(string $size, string $factor): void
    {
        Setting::set('receipt_font_size', $size, 'receipt');
        $sale = $this->sale();

        foreach (['thermal', 'a4'] as $format) {
            $this->actingAs($this->cashier)->get("/pos/receipts/{$sale->id}/print?format={$format}&auto=0")
                ->assertOk()->assertSee(":root { --fs: {$factor}; }", false);
        }
    }

    public function test_no_paper_text_keeps_a_fixed_size_outside_the_scale(): void
    {
        $sale = $this->sale();

        foreach (['thermal', 'a4'] as $format) {
            $html = $this->actingAs($this->cashier)->get("/pos/receipts/{$sale->id}/print?format={$format}&auto=0")->getContent();
            preg_match_all('/font-size:\s*([^;}]+)/', $html, $m);
            $fixed = array_values(array_filter($m[1], fn ($v) => ! str_contains($v, 'var(--fs)')));

            // the only fixed size left is the on-screen Print / Close button, which is not paper
            $this->assertCount(1, $fixed, "{$format}: ".implode(' | ', $fixed));
            $this->assertMatchesRegularExpression('/^1[34]px$/', trim($fixed[0]));
        }
    }

    public function test_the_silent_print_carries_the_size_too(): void
    {
        config(['nativephp-internal.running' => true, 'nativephp-internal.api_url' => 'http://127.0.0.1:4000/api/']);
        Http::fake(['*/system/print' => Http::response('OK', 200)]);
        Setting::set('receipt_font_size', 'large', 'receipt');
        $sale = $this->sale();

        $this->actingAs($this->cashier)->postJson("/pos/receipts/{$sale->id}/print-thermal")->assertOk();
        $this->actingAs($this->cashier)->postJson("/pos/receipts/{$sale->id}/print-a4")->assertOk();

        Http::assertSent(fn (HttpRequest $r) => str_contains(rawurldecode($r['html']), ':root { --fs: 1.2; }') && str_contains(rawurldecode($r['html']), 'calc(11px * var(--fs))'));
        Http::assertSent(fn (HttpRequest $r) => str_contains(rawurldecode($r['html']), ':root { --fs: 1.2; }') && str_contains(rawurldecode($r['html']), 'calc(10pt * var(--fs))'));
    }

    public function test_the_arabic_receipt_scales_too(): void
    {
        Setting::set('receipt_font_size', 'large', 'receipt');
        Setting::set('receipt_language', 'ar', 'receipt');
        $sale = $this->sale();

        $this->actingAs($this->cashier)->get("/pos/receipts/{$sale->id}/print?format=thermal&auto=0")
            ->assertOk()->assertSee('dir="rtl"', false)->assertSee(":root { --fs: 1.2; }", false)
            ->assertSee("font-family: 'Cairo'", false);
    }

    public function test_the_widths_keep_their_layout_at_the_large_size(): void
    {
        Setting::set('receipt_font_size', 'large', 'receipt');
        $sale = $this->sale();

        foreach ([58, 80] as $width) {
            Setting::set('receipt_width', $width, 'receipt', 'int');
            $this->actingAs($this->cashier)->get("/pos/receipts/{$sale->id}/print?format=thermal&auto=0")
                ->assertOk()
                ->assertSee("@page { size: {$width}mm auto; margin: 0; }", false)   // the paper is still the paper
                ->assertSee("width: {$width}mm", false)
                ->assertSee('padding: 4mm', false);                                  // and the mm layout is untouched
        }
    }

    public function test_an_unknown_stored_size_reads_as_normal(): void
    {
        Setting::set('receipt_font_size', 'gigantic', 'receipt');
        $sale = $this->sale();

        $this->actingAs($this->cashier)->get("/pos/receipts/{$sale->id}/print?format=thermal&auto=0")
            ->assertOk()->assertSee(':root { --fs: 1; }', false);
    }

    public function test_a_custom_template_is_not_scaled(): void
    {
        Setting::set('receipt_font_size', 'large', 'receipt');
        PrintTemplate::create([
            'name' => 'Mine', 'type' => 'receipt', 'is_active' => true, 'is_default' => true,
            'template_html' => '<html><body style="font-size: 12px"><p>{{ receipt_number }}</p></body></html>',
        ]);
        $sale = $this->sale();

        $this->actingAs($this->cashier)->get("/pos/receipts/{$sale->id}/print?format=thermal&auto=0")
            ->assertOk()->assertSee('font-size: 12px', false)->assertDontSee('--fs', false);
    }

    public function test_the_receipt_tab_saves_and_validates_the_size(): void
    {
        $admin = User::factory()->admin()->create();

        foreach (['small', 'large', 'normal'] as $size) {
            $this->actingAs($admin)->post(route('settings.update'), ['group' => 'receipt', 'settings' => ['receipt_font_size' => $size, 'receipt_width' => 80]])
                ->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame($size, Setting::get('receipt_font_size'));
        }

        $this->actingAs($admin)->post(route('settings.update'), ['group' => 'receipt', 'settings' => ['receipt_font_size' => 'huge', 'receipt_width' => 80]])
            ->assertSessionHasErrors('settings.receipt_font_size');
        $this->assertSame('normal', Setting::get('receipt_font_size'));
    }

    public function test_the_receipt_tab_offers_the_sizes_and_says_templates_are_not_scaled_in_both_languages(): void
    {
        $admin = User::factory()->admin()->create();
        Setting::set('receipt_font_size', 'large', 'receipt');

        $this->actingAs($admin)->get('/settings?tab=receipt')->assertOk()
            ->assertSee('Receipt text size')->assertSee('name="settings[receipt_font_size]"', false)
            ->assertSee('value="large" selected', false)->assertSee('Small')->assertSee('Normal')->assertSee('Large')
            ->assertSee('keeps its own sizes and is not scaled');

        $admin->update(['language' => 'ar']);
        $this->actingAs($admin)->get('/settings?tab=receipt')->assertOk()
            ->assertSee('حجم نص الإيصال')->assertSee('ولا يتأثر');
    }

    public function test_the_test_print_shows_the_size_typed_before_it_is_saved(): void
    {
        $admin = User::factory()->admin()->create();
        Setting::set('receipt_font_size', 'normal', 'receipt');

        $this->actingAs($admin)->post('/settings/receipt/test-print', ['group' => 'receipt', 'settings' => ['receipt_width' => 80, 'receipt_font_size' => 'large']])
            ->assertOk()->assertSee(':root { --fs: 1.2; }', false);
        $this->actingAs($admin)->post('/settings/receipt/test-print', ['group' => 'receipt', 'settings' => ['receipt_width' => 80]])
            ->assertOk()->assertSee(':root { --fs: 1; }', false);                     // nothing typed: the saved one
        $this->assertSame('normal', Setting::get('receipt_font_size'));
    }

    // ============================ B) interface size ============================

    public function test_the_column_is_nullable_and_null_is_100(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'ui_scale'));
        $user = User::factory()->create();

        $this->assertNull($user->fresh()->ui_scale);
        $this->assertSame(100, $user->fresh()->uiScale());
    }

    /** @return array<string, array{string}> */
    public static function roles(): array
    {
        return ['cashier' => [User::ROLE_CASHIER], 'stock' => [User::ROLE_STOCK], 'manager' => [User::ROLE_MANAGER], 'admin' => [User::ROLE_ADMIN], 'super_admin' => [User::ROLE_SUPER_ADMIN]];
    }

    #[DataProvider('roles')]
    public function test_every_role_sets_its_own_size_without_any_settings_access(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);

        foreach ([90, 110, 125, 100] as $scale) {
            $this->actingAs($user)->from('/profile')->patch(route('profile.ui-scale'), ['ui_scale' => $scale])->assertRedirect('/profile');
            $this->assertSame($scale, $user->fresh()->ui_scale);
            $this->assertSame($scale, $user->fresh()->uiScale());
        }
    }

    public function test_only_the_four_sizes_are_accepted(): void
    {
        $user = User::factory()->create(['ui_scale' => 110]);

        foreach ([80, 95, 130, 200, 0, -100, 'abc', '', '12.5', null, [110]] as $bad) {
            $this->actingAs($user)->patch(route('profile.ui-scale'), ['ui_scale' => $bad])->assertSessionHasErrors('ui_scale');
            $this->assertSame(110, $user->fresh()->ui_scale, 'unchanged by '.json_encode($bad));
        }
        $this->actingAs($user)->patch(route('profile.ui-scale'))->assertSessionHasErrors('ui_scale');
    }

    public function test_a_user_can_only_ever_change_their_own_size(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->create(['ui_scale' => 90]);

        // There is no user in the request to point at: extra fields are ignored.
        $this->actingAs($admin)->patch(route('profile.ui-scale'), ['ui_scale' => 125, 'user_id' => $other->id, 'id' => $other->id, 'user' => $other->id])
            ->assertSessionHasNoErrors();

        $this->assertSame(125, $admin->fresh()->ui_scale);
        $this->assertSame(90, $other->fresh()->ui_scale, 'an admin cannot force a size on somebody else');
        $this->assertSame(1, User::whereNotNull('ui_scale')->where('id', $admin->id)->count());
    }

    public function test_the_user_screens_cannot_set_it(): void
    {
        $super = User::factory()->superAdmin()->create();
        $target = User::factory()->create();

        $this->actingAs($super)->post(route('users.store'), [
            'name' => 'New', 'username' => 'newone', 'password' => 'secret123', 'password_confirmation' => 'secret123',
            'role' => User::ROLE_CASHIER, 'language' => 'en', 'is_active' => 1, 'ui_scale' => 125,
        ])->assertSessionHasNoErrors();
        $this->assertNull(User::where('username', 'newone')->sole()->ui_scale);

        $this->actingAs($super)->put(route('users.update', $target), [
            'name' => $target->name, 'username' => $target->username, 'role' => $target->role,
            'language' => 'en', 'max_discount_pct' => 0, 'is_active' => 1, 'ui_scale' => 125,
        ]);
        $this->assertNull($target->fresh()->ui_scale);

        $this->assertNull(User::create(['name' => 'X', 'username' => 'xx', 'password' => 'secret123', 'role' => 'cashier', 'language' => 'en', 'is_active' => true, 'ui_scale' => 125])->fresh()->ui_scale);
    }

    public function test_a_guest_cannot_use_the_route(): void
    {
        $this->patch(route('profile.ui-scale'), ['ui_scale' => 125])->assertRedirect(route('login'));
    }

    public function test_the_app_layout_carries_the_users_size_and_two_users_differ(): void
    {
        $small = User::factory()->manager()->create(['ui_scale' => 90]);
        $big = User::factory()->manager()->create(['ui_scale' => 125]);
        $plain = User::factory()->manager()->create();

        $this->actingAs($small)->get('/expenses')->assertOk()->assertSee('html { font-size: 90%; }', false);
        $this->actingAs($big)->get('/expenses')->assertOk()->assertSee('html { font-size: 125%; }', false)->assertDontSee('html { font-size: 90%; }', false);
        $this->actingAs($plain)->get('/expenses')->assertOk()->assertSee('html { font-size: 100%; }', false);
    }

    public function test_the_pos_layout_carries_it_too_and_it_survives_a_reload(): void
    {
        $this->cashier->forceFill(['ui_scale' => 110])->save();

        foreach (range(1, 2) as $_) {
            $this->actingAs($this->cashier)->get('/pos')->assertOk()->assertSee('html { font-size: 110%; }', false);
        }
    }

    public function test_the_login_page_and_a_demo_build_stay_at_100(): void
    {
        $this->get('/login')->assertOk()->assertDontSee('html { font-size:', false);

        $user = User::factory()->manager()->create(['ui_scale' => 125]);
        config(['pos.demo_mode' => true]);
        $this->actingAs($user)->get('/expenses')->assertOk()->assertSee('html { font-size: 100%; }', false)
            ->assertDontSee('name="ui_scale"', false);                           // and no switcher to change it
    }

    public function test_the_switcher_is_in_the_menus_and_on_the_profile_page_with_the_current_step_marked(): void
    {
        $user = User::factory()->manager()->create(['ui_scale' => 110]);

        foreach (['/expenses', '/profile'] as $url) {
            $this->actingAs($user)->get($url)->assertOk()
                ->assertSee(route('profile.ui-scale'), false)
                ->assertSee('value="110" aria-pressed="true"', false)
                ->assertSee('value="125" aria-pressed="false"', false)
                ->assertSee('Interface size');
        }
        $this->actingAs($user)->get('/profile')->assertSee('My preferences');

        $this->cashier->forceFill(['ui_scale' => 90])->save();
        $this->actingAs($this->cashier)->get('/pos')->assertOk()
            ->assertSee('My preferences')->assertSee('Interface size')->assertSee('value="90" aria-pressed="true"', false);
    }

    public function test_the_switcher_is_arabic_for_an_arabic_user(): void
    {
        $user = User::factory()->manager()->create(['language' => 'ar']);

        $this->actingAs($user)->get('/profile')->assertOk()->assertSee('تفضيلاتي')->assertSee('حجم الواجهة');
    }

    public function test_small_text_sizes_follow_the_interface_size(): void
    {
        foreach (['pos/sell', 'shifts/open', 'shifts/close', 'users/form', 'expenses/index'] as $view) {
            $this->assertDoesNotMatchRegularExpression('/text-\[\d+px\]/', file_get_contents(resource_path("views/{$view}.blade.php")), "{$view} has a fixed-px text size");
        }
    }
}
