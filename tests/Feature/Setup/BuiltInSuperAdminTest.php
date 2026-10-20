<?php

namespace Tests\Feature\Setup;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Providers\NativeAppServiceProvider;
use App\Support\Activation;
use App\Support\License;
use App\Support\SuperAdmin;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * A fresh install carries the vendor's super_admin from its first launch: the
 * username, name and a bcrypt hash come from config (POS_SUPER_ADMIN_*), the
 * account is created by SuperAdmin::ensure(), and there is no setup screen.
 */
class BuiltInSuperAdminTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'vendor-pre-agreed-pass';

    protected function setUp(): void
    {
        parent::setUp();

        // Pinned here so a developer's own .env (a demo flag, a real hash) can't
        // change what these tests are about.
        config([
            'pos.demo_mode' => false,
            'pos.super_admin.username' => 'buildsyntax',
            'pos.super_admin.name' => 'Build Syntax Support',
            'pos.super_admin.password_hash' => Hash::make(self::PASSWORD),
        ]);
    }

    private function vendor(): User
    {
        return User::where('role', User::ROLE_SUPER_ADMIN)->sole();
    }

    private function signInAsVendor(): void
    {
        $this->post('/login', ['login' => 'buildsyntax', 'password' => self::PASSWORD])
            ->assertRedirect('/pos');
    }

    // --- Creation -----------------------------------------------------------

    public function test_a_fresh_database_gets_exactly_one_super_admin_who_can_sign_in(): void
    {
        $user = SuperAdmin::ensure();

        $this->assertNotNull($user);
        $this->assertSame(1, User::count());

        $vendor = $this->vendor();
        $this->assertSame('buildsyntax', $vendor->username);
        $this->assertSame('Build Syntax Support', $vendor->name);
        $this->assertTrue($vendor->is_active);
        $this->assertSame(100.0, (float) $vendor->max_discount_pct);
        $this->assertNotNull($vendor->email_verified_at);
        $this->assertNull($vendor->email);

        $this->signInAsVendor();
        $this->assertAuthenticatedAs($vendor);
    }

    public function test_a_wrong_password_does_not_sign_in(): void
    {
        SuperAdmin::ensure();

        $this->post('/login', ['login' => 'buildsyntax', 'password' => 'not-the-password'])
            ->assertSessionHasErrors('login');
        $this->assertGuest();
    }

    public function test_the_configured_hash_is_stored_verbatim_not_hashed_again(): void
    {
        // Cost 10 against BCRYPT_ROUNDS=4: assigning this through the `hashed`
        // cast would throw ("Could not verify the hashed value's configuration").
        $hash = password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 10]);
        config(['pos.super_admin.password_hash' => $hash]);

        SuperAdmin::ensure();

        $this->assertSame($hash, $this->vendor()->getAuthPassword());
        $this->assertTrue(password_verify(self::PASSWORD, $this->vendor()->getAuthPassword()));

        $this->signInAsVendor();
    }

    public function test_the_account_follows_the_store_language_and_is_audited(): void
    {
        Setting::set('language', 'ar', 'appearance');

        $user = SuperAdmin::ensure();

        $this->assertSame('ar', $user->language);
        $this->assertDatabaseHas((new AuditLog)->getTable(), [
            'action' => 'super_admin_provisioned',
            'model_id' => $user->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_a_fresh_install_is_starter_until_the_vendor_picks_a_tier(): void
    {
        $this->assertSame('premium', License::tier(), 'precondition: an unset tier reads as the legacy default');

        SuperAdmin::ensure();

        $this->assertSame('starter', License::tier());
        $this->assertSame(0, License::seatsUsed(), 'the vendor account takes no seat');
    }

    public function test_an_already_chosen_tier_is_left_alone(): void
    {
        Setting::set('tier', 'standard', 'license');

        SuperAdmin::ensure();

        $this->assertSame('standard', License::tier());
    }

    // --- Idempotence and existing installs ----------------------------------

    public function test_running_ensure_twice_keeps_one_account_and_a_changed_password(): void
    {
        SuperAdmin::ensure();
        $this->vendor()->update(['password' => 'changed-from-inside-the-app']);

        $this->assertNull(SuperAdmin::ensure());

        $this->assertSame(1, User::where('role', User::ROLE_SUPER_ADMIN)->count());
        $this->assertTrue(Hash::check('changed-from-inside-the-app', $this->vendor()->password));
        $this->assertFalse(Hash::check(self::PASSWORD, $this->vendor()->password));
    }

    public function test_an_install_that_already_has_a_super_admin_is_untouched(): void
    {
        $existing = User::factory()->superAdmin()->create(['username' => 'owner']);
        $passwordBefore = $existing->password;

        $this->assertNull(SuperAdmin::ensure());

        $this->assertSame(1, User::count());
        $this->assertSame($passwordBefore, $existing->fresh()->password);
        $this->assertNull(Setting::get('tier'), 'no licence state is written for an existing install');
    }

    // --- Refusals ------------------------------------------------------------

    public function test_a_demo_build_never_gets_a_super_admin(): void
    {
        config(['pos.demo_mode' => true]);

        $this->assertNull(SuperAdmin::ensure());

        $this->assertSame(0, User::count());
    }

    public function test_a_missing_hash_creates_nothing_and_the_login_page_says_so(): void
    {
        Log::spy();
        config(['pos.super_admin.password_hash' => '']);

        $this->assertNull(SuperAdmin::ensure());

        $this->assertSame(0, User::count());
        Log::shouldHaveReceived('error')->once();

        $this->get('/')->assertRedirect('/login');
        $this->get('/login')->assertOk()
            ->assertSee('This installation is not provisioned. Contact your provider.');

        $this->withSession(['locale' => 'ar'])->get('/login')->assertOk()
            ->assertSee(__('auth.not_provisioned', [], 'ar'));
    }

    public function test_a_plain_password_in_place_of_the_hash_is_refused(): void
    {
        Log::spy();
        config(['pos.super_admin.password_hash' => self::PASSWORD]);

        $this->assertNull(SuperAdmin::ensure());

        $this->assertSame(0, User::count());
        Log::shouldHaveReceived('error')->once();
    }

    public function test_a_username_held_by_a_client_account_is_not_taken_over(): void
    {
        Log::spy();
        $client = User::factory()->admin()->create(['username' => 'buildsyntax']);

        $this->assertNull(SuperAdmin::ensure());

        $this->assertSame(1, User::count());
        $this->assertSame(User::ROLE_ADMIN, $client->fresh()->role);
        Log::shouldHaveReceived('error')->once()->withArgs(fn ($m) => str_contains($m, 'buildsyntax'));
    }

    public function test_the_login_page_shows_no_warning_once_an_account_exists_or_in_demo(): void
    {
        $this->get('/login')->assertSee('not provisioned');

        User::factory()->create();
        $this->get('/login')->assertOk()->assertDontSee('not provisioned');

        User::query()->delete();
        config(['pos.demo_mode' => true]);
        $this->get('/login')->assertOk()->assertDontSee('not provisioned');
    }

    // --- Where it runs -------------------------------------------------------

    public function test_the_default_seeder_creates_config_and_the_super_admin(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertTrue(Setting::query()->exists());
        $this->assertSame(['super_admin'], User::pluck('role')->all());
        $this->assertSame('buildsyntax', User::sole()->username);
    }

    public function test_the_default_seeder_makes_no_account_without_a_hash(): void
    {
        config(['pos.super_admin.password_hash' => '']);

        $this->seed(DatabaseSeeder::class);

        $this->assertTrue(Setting::query()->exists());
        $this->assertSame(0, User::count(), 'no guessable fallback password');
    }

    public function test_every_launch_ensures_the_account_even_when_settings_already_exist(): void
    {
        $this->seed(DatabaseSeeder::class);
        User::query()->delete();

        // The launch hook: a settings table that already has rows skips db:seed,
        // so the account must come back through the explicit call after it.
        $boot = new \ReflectionMethod(NativeAppServiceProvider::class, 'bootstrapDatabase');
        $boot->invoke(new NativeAppServiceProvider);

        $this->assertSame('buildsyntax', $this->vendor()->username);
    }

    public function test_the_ensure_command_creates_then_reports_it_is_already_there(): void
    {
        $this->artisan('pos:ensure-super-admin')
            ->expectsOutputToContain("Created super_admin 'buildsyntax'")
            ->assertSuccessful();

        $this->artisan('pos:ensure-super-admin')
            ->expectsOutputToContain('already exists')
            ->assertSuccessful();

        $this->assertSame(1, User::count());
    }

    public function test_the_ensure_command_fails_loudly_without_a_hash(): void
    {
        config(['pos.super_admin.password_hash' => '']);

        $this->artisan('pos:ensure-super-admin')->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_reset_data_then_ensure_brings_the_super_admin_back(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->artisan('pos:reset-data --force')->assertSuccessful();
        $this->assertSame(0, User::count());

        SuperAdmin::ensure();

        $this->signInAsVendor();
        $this->assertSame('starter', License::tier(), 'settings survive a reset, so the tier does too');
    }

    // --- No setup screen -----------------------------------------------------

    public function test_root_goes_to_login_and_the_setup_screen_is_gone(): void
    {
        $this->get('/')->assertRedirect('/login');

        SuperAdmin::ensure();

        $this->get('/')->assertRedirect('/login');
        $this->get('/login')->assertOk();
        $this->get('/setup')->assertNotFound();
        $this->post('/setup', ['username' => 'intruder', 'password' => 'whatever-it-takes'])->assertNotFound();
        $this->assertSame(1, User::count());
    }

    // --- The trial starts at the vendor's first sign-in ----------------------

    public function test_the_trial_has_not_started_until_the_vendor_signs_in(): void
    {
        SuperAdmin::ensure();

        $this->assertNull(Setting::get('installed_at'));
        $this->assertNull(Setting::get('activation_status'));
        $this->assertFalse(Activation::isLocked());
    }

    public function test_the_first_vendor_sign_in_starts_the_14_day_trial(): void
    {
        SuperAdmin::ensure();

        $this->signInAsVendor();

        $this->assertSame(Activation::TRIAL, Activation::status());
        $this->assertNotNull(Setting::get('installed_at'));
        $this->assertSame(14, Activation::daysRemaining());
        $this->assertFalse(Activation::isLocked());
    }

    public function test_a_second_sign_in_does_not_move_the_start_date(): void
    {
        SuperAdmin::ensure();
        $this->signInAsVendor();
        $installedAt = Setting::get('installed_at');

        $this->post('/logout');
        $this->travel(2)->days();
        $this->signInAsVendor();

        $this->assertSame($installedAt, Setting::get('installed_at'));
        $this->assertSame(Activation::TRIAL, Activation::status());
    }

    public function test_a_client_sign_in_does_not_start_the_trial(): void
    {
        $client = User::factory()->admin()->create();

        $this->post('/login', ['login' => $client->username, 'password' => 'password'])->assertRedirect('/pos');

        $this->assertNull(Setting::get('installed_at'));
        $this->assertNull(Setting::get('activation_status'));
    }

    public function test_an_activated_install_stays_activated_when_the_vendor_signs_in(): void
    {
        SuperAdmin::ensure();
        Setting::set('activation_status', Activation::ACTIVATED, 'activation');

        $this->signInAsVendor();

        $this->assertSame(Activation::ACTIVATED, Activation::status());
    }
}
