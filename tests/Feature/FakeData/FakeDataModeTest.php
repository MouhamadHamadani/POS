<?php

namespace Tests\Feature\FakeData;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\User;
use App\Providers\NativeAppServiceProvider;
use App\Services\ShiftService;
use App\Support\FakeData;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\FakeDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * Fake data mode: a realistic, consistent, repeatable fake business for development and testing, that never seeds
 * where it must not and that a production launch turns back into a clean database.
 */
class FakeDataModeTest extends TestCase
{
    use RefreshDatabase;

    private function seedFake(int $days = 2, ?int $seed = null): FakeDataSeeder
    {
        $seeder = app(FakeDataSeeder::class)->configure($days, $seed ?? FakeDataSeeder::DEFAULT_SEED);
        $seeder->setContainer($this->app)->__invoke();

        return $seeder;
    }

    private function environment(string $env): void
    {
        $this->app['env'] = $env;
    }

    /** The ordinary seeder, run directly: `db:seed` itself stops to ask for confirmation in a production environment. */
    private function runDatabaseSeeder(): void
    {
        app(DatabaseSeeder::class)->setContainer($this->app)->__invoke();
    }

    /** Every table a clean install leaves empty. */
    private const BUSINESS_TABLES = [
        'products', 'categories', 'customers', 'suppliers', 'purchase_orders', 'purchase_order_items', 'shifts', 'sales',
        'sale_items', 'sale_payments', 'returns', 'return_items', 'expenses', 'cash_movements', 'held_sales',
        'inventory_adjustments', 'loyalty_transactions',
    ];

    // --- What it makes ---------------------------------------------------------------------------------------

    public function test_it_makes_a_realistic_business_that_is_consistent_and_browsable(): void
    {
        $this->seedFake(21);

        // Enough of everything to look like a shop.
        $this->assertSame(5, User::count());
        $this->assertGreaterThanOrEqual(36, Product::count());
        $this->assertSame(12, Customer::count());
        $this->assertSame(4, DB::table('suppliers')->count());
        $this->assertSame(4, DB::table('purchase_orders')->count());
        $this->assertSame(22, Shift::count(), '21 days back plus today');
        $this->assertGreaterThan(250, Sale::count());

        // Every kind of money the till takes, both channels, and the things that go wrong in a real shop.
        $methods = Sale::query()->pluck('payment_method')->unique()->all();
        foreach (['cash_usd', 'cash_lbp', 'mixed', 'card', 'wish', 'split'] as $method) {
            $this->assertContains($method, $methods);
        }
        $this->assertGreaterThan(20, Sale::where('channel', Sale::CHANNEL_ONLINE)->count());
        $this->assertSame(['facebook', 'instagram', 'phone', 'whatsapp'], Sale::whereNotNull('channel_source')->pluck('channel_source')->unique()->sort()->values()->all());
        $this->assertGreaterThan(0, Sale::where('status', Sale::STATUS_VOIDED)->count());
        $this->assertGreaterThan(0, Sale::where('status', Sale::STATUS_PARTIAL_REFUND)->count());
        $this->assertGreaterThan(0, DB::table('returns')->count());
        $this->assertGreaterThan(0, DB::table('expenses')->where('paid_from', 'drawer')->count());
        $this->assertGreaterThan(0, DB::table('expenses')->where('paid_from', 'outside')->count());
        $this->assertGreaterThan(0, DB::table('loyalty_transactions')->count());
        $this->assertSame(2, DB::table('held_sales')->count());
        $this->assertEqualsCanonicalizing(['received', 'partial', 'sent', 'draft'], DB::table('purchase_orders')->pluck('status')->all());

        // Plainly fake, and bilingual.
        $this->assertSame(0, Customer::where('name', 'not like', '% Test')->count());
        $this->assertSame(0, DB::table('suppliers')->where('name', 'not like', '%(Test)%')->count());
        $this->assertSame(0, Product::whereNull('name_ar')->orWhere('name_ar', '')->count());
        $this->assertSame('premium', Setting::get('tier'), 'every feature on');
        $this->assertSame('activated', Setting::get('activation_status'), 'never locked by the trial clock');

        // Nothing dated after now.
        $now = now()->addMinute();
        foreach (['sales', 'inventory_adjustments', 'audit_logs', 'expenses', 'shifts'] as $table) {
            $this->assertSame(0, DB::table($table)->where('created_at', '>', $now)->count(), "{$table} has rows from the future");
        }

        // The data hangs together the way real data does.
        foreach (Sale::with('payments')->where('status', '!=', Sale::STATUS_VOIDED)->get() as $sale) {
            $nonCash = (float) $sale->payments->whereIn('method', ['card', 'wish', 'credit'])->sum('amount_usd');
            $rate = (float) $sale->exchange_rate;
            $cashKept = (float) $sale->amount_tendered_usd + (float) $sale->amount_tendered_lbp / $rate
                - (float) $sale->change_usd - (float) $sale->change_lbp / $rate;
            $this->assertEqualsWithDelta((float) $sale->total_usd, $nonCash + $cashKept, 0.02, "{$sale->receipt_number}: payment lines do not add up");
        }

        foreach (Product::where('track_stock', true)->get() as $product) {
            $this->assertGreaterThanOrEqual(0, (float) $product->stock_qty, "{$product->name} went negative");
            $this->assertEqualsWithDelta((float) $product->stock_qty, (float) DB::table('inventory_adjustments')->where('product_id', $product->id)->sum('qty_change'), 0.0001, "{$product->name}: stock does not match its movement log");
        }

        $shifts = app(ShiftService::class);
        foreach (Shift::where('status', Shift::STATUS_CLOSED)->get() as $shift) {
            $totals = $shifts->totals($shift);
            $this->assertEqualsWithDelta($totals['expected_usd'], (float) $shift->expected_cash_usd, 0.0001, "shift {$shift->id}: expected cash is stale");
            $this->assertEqualsWithDelta($totals['expected_lbp'], (float) $shift->expected_cash_lbp, 0.01);
        }
        $open = Shift::where('status', Shift::STATUS_OPEN)->get();
        $this->assertCount(1, $open, 'one open shift: today\'s');
        $this->assertSame('fake_cashier', $open->first()->user->username);

        $this->assertScreensRender();
    }

    /** With real-looking data behind them, every screen a person would open renders. */
    private function assertScreensRender(): void
    {
        $admin = User::where('username', 'fake_admin')->first();
        $split = Sale::where('payment_method', 'split')->where('channel', 'online')->first() ?? Sale::where('payment_method', 'split')->first();

        foreach ([
            '/dashboard', '/products', '/products/movements', '/categories', '/customers', '/suppliers', '/purchase-orders',
            '/sales', route('sales.show', $split), '/returns', '/expenses', '/reports', '/reports/sales/daily',
            '/reports/sales/by-product', '/reports/inventory/stock-levels', '/reports/inventory/by-date',
            '/reports/financial/expenses', '/reports/financial/pnl', '/users', '/settings',
            route('receipts.print', ['sale' => $split, 'format' => 'thermal', 'auto' => 0]),
            route('receipts.print', ['sale' => $split, 'format' => 'a4', 'auto' => 0]),
        ] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }
        $this->actingAs($admin)->get('/reports/sales/daily?format=pdf')->assertOk();

        $cashier = User::where('username', 'fake_cashier')->first();
        $this->actingAs($cashier)->get('/pos')->assertOk();
        $this->actingAs($cashier)->get('/shifts/close')->assertOk();

        // The till can really sell on it: the open shift, the seeded stock and prices.
        $product = Product::where('track_stock', true)->where('stock_qty', '>', 5)->first();
        $this->actingAs($cashier)->postJson('/pos/api/sales', [
            'cart' => [['product_id' => $product->id, 'qty' => 1]],
            'payment' => ['amount_usd' => 500],
        ])->assertCreated();
    }

    public function test_the_same_seed_makes_the_same_shop_and_another_seed_a_different_one(): void
    {
        Carbon::setTestNow('2026-10-21 15:00:00');
        $shop = fn () => Sale::orderBy('id')->get(['receipt_number', 'payment_method', 'total_usd', 'channel', 'status'])->toArray();

        $this->seedFake(3);
        $first = $shop();
        FakeData::reset();

        $this->seedFake(3);
        $this->assertSame($first, $shop(), 'same seed, same shop');
        FakeData::reset();

        $this->seedFake(3, 7);
        $this->assertNotSame($first, $shop(), 'a different seed is a different shop');

        Carbon::setTestNow();
    }

    public function test_the_seeder_gives_back_a_clock_that_a_test_had_frozen(): void
    {
        Carbon::setTestNow($frozen = Carbon::parse('2026-10-21 15:00:00'));

        $this->seedFake(1);

        $this->assertTrue(Carbon::hasTestNow());
        $this->assertTrue(Carbon::now()->equalTo($frozen));
        Carbon::setTestNow();
    }

    // --- Where it refuses -------------------------------------------------------------------------------------

    public function test_it_never_seeds_in_production(): void
    {
        $this->environment('production');

        $this->assertStringContainsString('production', (string) FakeData::problem());
        $this->expectException(RuntimeException::class);
        $this->seedFake(1);
    }

    public function test_it_never_seeds_a_demo_build(): void
    {
        config(['pos.demo_mode' => true]);

        $this->assertStringContainsString('demo', strtolower((string) FakeData::problem()));
        $this->assertFalse(FakeData::enabled());
    }

    public function test_it_leaves_a_database_that_already_holds_other_data_alone(): void
    {
        Product::factory()->create(); // a developer's own test product, with no marker on it

        $this->assertStringContainsString('did not come from', (string) FakeData::problem());
        $this->assertStringContainsString('did not come from', (string) FakeData::problem(rebuild: true));
        $this->assertSame(1, Product::count());
    }

    public function test_it_does_not_seed_a_second_time_unless_asked_to_rebuild(): void
    {
        $this->seedFake(1);

        $this->assertStringContainsString('--fresh', (string) FakeData::problem());
        $this->assertNull(FakeData::problem(rebuild: true));
    }

    public function test_the_marker_is_written_before_anything_else_so_a_failed_seed_can_still_be_purged(): void
    {
        // A seed that dies halfway: the marker is already there, and so is a partial business.
        $seeder = app(FakeDataSeeder::class)->configure(1, 1);
        config(['pos.fake_password' => ['not a string' => 'makes User::create blow up']]);
        try {
            $seeder->setContainer($this->app)->__invoke();
            $this->fail('expected the seed to fail');
        } catch (\Throwable) {
        }
        config(['pos.fake_password' => 'test1234']);

        $this->assertTrue(FakeData::seeded());
        FakeData::reset();
        $this->assertFalse(FakeData::seeded());
        $this->assertFalse(FakeData::hasBusinessData());
    }

    // --- Automatic seeding -------------------------------------------------------------------------------------

    public function test_the_flag_seeds_an_empty_database_through_the_ordinary_seeder_and_the_default_leaves_it_empty(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->assertSame(0, Product::count(), 'the default seeds no catalogue');

        FakeData::reset();
        config(['pos.fake_data' => true, 'pos.fake_data_days' => 1]);
        $this->seed(DatabaseSeeder::class);

        $this->assertTrue(FakeData::seeded());
        $this->assertGreaterThan(30, Product::count());
        $this->assertSame(2, Shift::count(), 'one day back plus today');
    }

    public function test_the_flag_does_nothing_in_production_or_on_a_database_with_data_or_when_already_seeded(): void
    {
        config(['pos.fake_data' => true, 'pos.fake_data_days' => 1]);

        $this->environment('production');
        $this->runDatabaseSeeder();
        $this->assertSame(0, Product::count());

        $this->environment('testing');
        Product::factory()->create();
        $this->runDatabaseSeeder();
        $this->assertSame(1, Product::count(), 'real data is not buried under fake data');
        $this->assertFalse(FakeData::seeded());
        FakeData::reset();

        $this->runDatabaseSeeder();
        $products = Product::count();
        $this->assertGreaterThan(30, $products);
        $this->runDatabaseSeeder(); // run again: no second helping
        $this->assertSame($products, Product::count());
    }

    // --- Back to clean -----------------------------------------------------------------------------------------

    public function test_a_purge_leaves_what_a_freshly_installed_database_has(): void
    {
        $categories = DB::table('expense_categories')->count();
        $this->seedFake(2);

        $removed = FakeData::reset();

        $this->assertGreaterThan(500, $removed);
        foreach (self::BUSINESS_TABLES as $table) {
            $this->assertSame(0, DB::table($table)->count(), "{$table} still has rows");
        }
        $this->assertSame(0, User::count(), 'the fake accounts are gone');
        $this->assertFalse(FakeData::seeded());
        $this->assertNull(Setting::get('tier'));
        $this->assertNull(Setting::get('activation_status'), 'a clean install starts its own trial');
        $this->assertSame(90000.0, (float) Setting::get('exchange_rate'), 'the default rate, not the fake shop\'s');
        $this->assertSame('LebaSouk', Setting::get('business_name'));
        $this->assertSame($categories, DB::table('expense_categories')->count(), 'reference data the migration inserted stays');
        $this->assertGreaterThan(0, DB::table('taxes')->count());
        $this->assertGreaterThan(0, DB::table('currencies')->count());
    }

    public function test_a_purge_brings_back_the_built_in_super_admin_and_numbering_restarts_at_one(): void
    {
        config(['pos.demo_mode' => false, 'pos.super_admin.password_hash' => Hash::make('a-real-password')]);
        $this->seedFake(2);

        FakeData::reset();

        $this->assertSame(['buildsyntax'], User::pluck('username')->all());
        $this->assertSame('starter', Setting::get('tier'), 'a fresh install is Starter, as ever');

        $this->seedFake(1);
        $this->assertSame(1, (int) Sale::orderBy('id')->value('id'), 'AUTOINCREMENT started over');
    }

    public function test_a_production_launch_resets_a_fake_database_and_only_a_fake_one(): void
    {
        // Real data, no marker: a production launch leaves it exactly as it is.
        $this->environment('production');
        Product::factory()->create(['name' => 'A client product']);
        $this->assertFalse(FakeData::switchedToProduction());
        $this->assertFalse(FakeData::resetIfSwitchedToProduction());
        $this->assertSame(1, Product::count());
        FakeData::reset(); // back to empty, to start the next case from nothing

        // Fake data, launched in development: untouched.
        $this->environment('testing');
        $this->seedFake(1);
        $this->assertFalse(FakeData::resetIfSwitchedToProduction());
        $this->assertGreaterThan(30, Product::count());

        // ...and launched in production: cleaned, once.
        $this->environment('production');
        $this->assertTrue(FakeData::switchedToProduction());
        $this->assertTrue(FakeData::resetIfSwitchedToProduction());
        $this->assertSame(0, Product::count());
        $this->assertSame(0, Sale::count());
        $this->assertFalse(FakeData::resetIfSwitchedToProduction(), 'nothing left to reset');
    }

    public function test_the_desktop_bootstrap_cleans_a_fake_database_on_a_production_launch(): void
    {
        $this->seedFake(1);
        $this->environment('production');

        (new \ReflectionMethod(NativeAppServiceProvider::class, 'bootstrapDatabase'))->invoke(new NativeAppServiceProvider);

        $this->assertSame(0, Product::count());
        $this->assertSame(0, Sale::count());
        $this->assertFalse(FakeData::seeded());
        $this->assertSame(90000.0, (float) Setting::get('exchange_rate'), 'configured, as a fresh launch is');
    }

    public function test_a_demo_build_in_production_is_never_reset_by_this(): void
    {
        $this->seedFake(1);
        config(['pos.demo_mode' => true]);
        $this->environment('production');

        $this->assertFalse(FakeData::switchedToProduction());
    }

    // --- The command ---------------------------------------------------------------------------------------------

    public function test_the_command_seeds_reports_and_purges(): void
    {
        $this->artisan('pos:fake-data --days=2')
            ->expectsOutputToContain('fake_admin')
            ->assertSuccessful();
        $this->assertTrue(FakeData::seeded());

        $this->artisan('pos:fake-data')->expectsOutputToContain('already holds fake data')->assertFailed();
        $this->artisan('pos:fake-data --status')->expectsOutputToContain('Fake data here: yes')->assertSuccessful();

        $this->artisan('pos:fake-data --purge --force')->expectsOutputToContain('clean')->assertSuccessful();
        $this->assertFalse(FakeData::seeded());
        $this->assertSame(0, Product::count());
        $this->artisan('pos:fake-data --status')->expectsOutputToContain('Fake data here: no')->assertSuccessful();
    }

    public function test_the_command_rebuilds_with_fresh(): void
    {
        $this->artisan('pos:fake-data --days=1')->assertSuccessful();
        $first = Sale::count();

        $this->artisan('pos:fake-data --fresh --force --days=1 --seed=3')->assertSuccessful();

        $this->assertTrue(FakeData::seeded());
        $this->assertGreaterThan(30, Product::count());
        $this->assertNotSame($first, Sale::orderBy('id')->value('receipt_number') . Sale::count() . Sale::sum('total_usd'));
    }

    public function test_the_command_refuses_in_production_and_never_purges_data_it_did_not_make(): void
    {
        $this->environment('production');
        $this->artisan('pos:fake-data')->expectsOutputToContain('production')->assertFailed();

        $this->environment('testing');
        Product::factory()->create();
        $this->artisan('pos:fake-data')->expectsOutputToContain('did not come from')->assertFailed();
        $this->artisan('pos:fake-data --purge --force')->expectsOutputToContain('no fake data')->assertFailed();
        $this->artisan('pos:fake-data --fresh --force')->assertFailed();
        $this->assertSame(1, Product::count(), 'a database without the marker is never wiped');
    }

    // --- Releases ----------------------------------------------------------------------------------------------------

    public function test_a_release_build_refuses_a_development_env_with_fake_data_on(): void
    {
        $env = ['APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_KEY' => 'base64:abc', 'POS_SUPER_ADMIN_PASSWORD_HASH' => Hash::make('x')];

        $this->assertSame([], \App\Support\ReleaseEnv::problemsIn($env));
        $this->assertSame([], \App\Support\ReleaseEnv::problemsIn($env + ['POS_FAKE_DATA' => 'false']));

        $problems = \App\Support\ReleaseEnv::problemsIn($env + ['POS_FAKE_DATA' => 'true']);
        $this->assertCount(1, $problems);
        $this->assertStringContainsString('POS_FAKE_DATA', $problems[0]);
    }

    public function test_fake_data_never_ships_in_the_default_database_seeder_or_the_demo_template(): void
    {
        $this->assertFalse((bool) config('pos.fake_data'), 'off by default');
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, User::where('username', 'like', 'fake_%')->count());
        $this->assertFalse(FakeData::seeded());
        $this->assertSame(0, SalePayment::count());
    }
}
