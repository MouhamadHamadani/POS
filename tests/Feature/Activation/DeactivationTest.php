<?php

namespace Tests\Feature\Activation;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Activation;
use App\Support\License;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The vendor can lock an install by hand as well as unlock it. Pins: the lock
 * screen says the right thing for each reason, the vendor can never lock
 * themselves out of the way back, only a super_admin with their own password and
 * a ticked box can do it, and a lock still never touches a row of business data.
 */
class DeactivationTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/settings/activation/deactivate';

    private function install(string $status, int $daysAgo = 5): void
    {
        Setting::set('installed_at', now()->subDays($daysAgo)->toIso8601String(), 'activation');
        Setting::set('activation_status', $status, 'activation');
        Setting::set('activation_seen_at', now()->toIso8601String(), 'activation');
    }

    private function vendor(): User
    {
        return User::factory()->superAdmin()->create();
    }

    private function deactivate(User $by, array $data = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($by)->post(self::URL, $data + ['password' => 'password', 'confirm' => '1']);
    }

    // === Deactivating ===

    public function test_the_vendor_can_lock_an_activated_install_and_it_is_audited(): void
    {
        $this->install(Activation::ACTIVATED);
        $vendor = $this->vendor();

        $this->deactivate($vendor)->assertRedirect(route('settings.index', ['tab' => 'plan']));

        $this->assertSame(Activation::LOCKED, Activation::status());
        $this->assertSame(Activation::REASON_MANUAL, Activation::lockReason());
        $audit = AuditLog::query()->where('action', 'deactivate')->sole();
        $this->assertSame($vendor->id, $audit->user_id);
        $this->assertSame(['activation_status' => Activation::ACTIVATED], $audit->old_values);
        $this->assertSame(['activation_status' => Activation::LOCKED], $audit->new_values);
    }

    public function test_a_trial_install_can_be_locked_too(): void
    {
        $this->install(Activation::TRIAL, 3);

        $this->deactivate($this->vendor());

        $this->assertSame(Activation::LOCKED, Activation::status());
        $this->assertSame(Activation::REASON_MANUAL, Activation::lockReason());
        $this->assertDatabaseMissing((new AuditLog)->getTable(), ['action' => 'trial_expired']);
    }

    public function test_locking_an_already_locked_install_does_nothing(): void
    {
        $this->install(Activation::TRIAL, 20);
        $vendor = $this->vendor();
        $this->get('/'); // the trial lapses on the first request
        $this->assertSame(Activation::REASON_TRIAL_EXPIRED, Activation::lockReason());

        $this->deactivate($vendor)->assertRedirect();

        // Still the trial lock, not rewritten as a manual one, and nothing new in the audit log.
        $this->assertSame(Activation::REASON_TRIAL_EXPIRED, Activation::lockReason());
        $this->assertDatabaseMissing((new AuditLog)->getTable(), ['action' => 'deactivate']);
    }

    // === What the client and the vendor see afterwards ===

    public function test_the_client_is_paused_out_and_the_vendor_can_still_get_back_in(): void
    {
        $this->install(Activation::ACTIVATED);
        Product::factory()->count(2)->create();
        $vendor = $this->vendor();
        $admin = User::factory()->admin()->create();
        $cashier = User::factory()->create();

        $this->deactivate($vendor);

        foreach ([$admin, $cashier] as $client) {
            $this->actingAs($client)->get('/dashboard')->assertRedirect(route('locked'));
            $this->actingAs($client)->get('/pos')->assertRedirect(route('locked'));
        }
        $this->actingAs($admin)->getJson('/pos/api/products')->assertStatus(423);

        $this->actingAs($admin)->get('/locked')->assertOk()
            ->assertSee('This installation has been paused by your provider.')
            ->assertSee('Your data is safe')
            ->assertDontSee('14-day trial');

        // The vendor: lands on the Plan tab, sees why, and can activate again.
        $this->actingAs($vendor)->get('/dashboard')->assertRedirect(route('settings.index', ['tab' => 'plan']));
        $this->actingAs($vendor)->get('/settings?tab=plan')->assertOk()
            ->assertSee('Locked by provider')
            ->assertSee('Activate permanently')
            ->assertDontSee('Deactivate (lock now)');

        $this->actingAs($vendor)->post('/settings/activation', ['password' => 'password'])
            ->assertRedirect(route('settings.index', ['tab' => 'plan']));

        $this->assertSame(Activation::ACTIVATED, Activation::status());
        $this->assertNull(Activation::lockReason());
        $this->assertEmpty(Setting::get('activation_lock_reason'));
        $this->actingAs($admin)->get('/dashboard')->assertOk();
        $this->assertSame(2, Product::count()); // the lock only ever closed the door
    }

    public function test_the_paused_lock_screen_is_in_arabic_when_the_store_language_is_arabic(): void
    {
        $this->install(Activation::ACTIVATED);
        $client = User::factory()->admin()->create(['language' => 'ar']);
        $this->deactivate($this->vendor());

        $this->actingAs($client)->get('/locked')->assertSee('تم إيقاف هذه النسخة مؤقتاً');
    }

    public function test_a_provider_pause_is_not_mistaken_for_a_wrong_date(): void
    {
        $this->install(Activation::ACTIVATED);
        $this->deactivate($this->vendor());
        User::factory()->admin()->create();

        // A last-seen mark in the future is what a rolled-back clock looks like.
        Setting::set('activation_seen_at', now()->addDays(30)->toIso8601String(), 'activation');
        $this->assertTrue(Activation::clockRolledBack());

        $this->get('/locked')->assertSee('paused by your provider')->assertDontSee('Check the date');
    }

    // === Why it locked ===

    public function test_the_trial_running_out_records_its_reason_and_shows_the_trial_text(): void
    {
        $this->install(Activation::TRIAL, 15);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/dashboard')->assertRedirect(route('locked'));

        $this->assertSame(Activation::REASON_TRIAL_EXPIRED, Setting::get('activation_lock_reason'));
        $this->actingAs($admin)->get('/locked')->assertSee('Your 14-day trial has ended')->assertDontSee('paused');

        $this->actingAs($this->vendor())->get('/settings?tab=plan')
            ->assertSee('Trial expired')
            ->assertSee('Days remaining');
    }

    public function test_a_lock_written_before_reasons_existed_reads_as_the_trial(): void
    {
        $this->install(Activation::LOCKED, 30);
        User::factory()->admin()->create();

        $this->assertNull(Setting::get('activation_lock_reason'));
        $this->assertSame(Activation::REASON_TRIAL_EXPIRED, Activation::lockReason());
        $this->get('/locked')->assertSee('Your 14-day trial has ended');
    }

    public function test_activating_after_a_trial_lock_clears_the_reason(): void
    {
        $this->install(Activation::TRIAL, 15);
        $vendor = $this->vendor();
        $this->get('/');

        $this->actingAs($vendor)->post('/settings/activation', ['password' => 'password']);

        $this->assertNull(Activation::lockReason());
        $this->assertEmpty(Setting::get('activation_lock_reason'));
    }

    // === The Plan tab ===

    public function test_the_plan_tab_offers_deactivate_on_trial_and_activated_installs_and_shows_the_install_date(): void
    {
        $vendor = $this->vendor();

        // 40 days is a long-running activated install; a trial must still be inside its 14.
        foreach ([Activation::ACTIVATED => 40, Activation::TRIAL => 6] as $status => $daysAgo) {
            $this->install($status, $daysAgo);

            $this->actingAs($vendor)->get('/settings?tab=plan')->assertOk()
                ->assertSee('Deactivate (lock now)')
                ->assertSee('The client will be locked out immediately. No data is deleted. You can re-activate at any time.')
                ->assertSee('name="confirm"', false)
                ->assertSee(now()->subDays($daysAgo)->toDateString());
        }
    }

    public function test_the_plan_tab_renders_deactivate_in_arabic(): void
    {
        $this->install(Activation::ACTIVATED);
        Setting::set('language', 'ar', 'appearance');

        $this->actingAs(User::factory()->superAdmin()->create(['language' => 'ar']))->get('/settings?tab=plan')
            ->assertSee('إيقاف (قفل الآن)')
            ->assertSee('سيتم قفل النظام عند العميل فوراً');
    }

    // === Guards ===

    public function test_a_wrong_password_changes_nothing_on_either_action(): void
    {
        $vendor = $this->vendor();

        $this->install(Activation::ACTIVATED);
        $this->deactivate($vendor, ['password' => 'wrong'])->assertSessionHasErrors('password');
        $this->assertSame(Activation::ACTIVATED, Activation::status());

        $this->deactivate($vendor);
        $this->actingAs($vendor)->post('/settings/activation', ['password' => 'wrong'])->assertSessionHasErrors('password');
        $this->assertSame(Activation::LOCKED, Activation::status());
        $this->assertSame(Activation::REASON_MANUAL, Activation::lockReason());

        $this->assertSame(1, AuditLog::query()->where('action', 'deactivate')->count());
        $this->assertDatabaseMissing((new AuditLog)->getTable(), ['action' => 'activate']);
    }

    public function test_deactivating_needs_the_box_ticked_and_a_password(): void
    {
        $this->install(Activation::ACTIVATED);
        $vendor = $this->vendor();

        $this->actingAs($vendor)->post(self::URL, ['password' => 'password'])->assertSessionHasErrors('confirm');
        $this->actingAs($vendor)->post(self::URL, ['confirm' => '1'])->assertSessionHasErrors('password');

        $this->assertSame(Activation::ACTIVATED, Activation::status());
    }

    public function test_only_a_super_admin_can_reach_either_route(): void
    {
        $this->install(Activation::ACTIVATED);
        $clients = [
            User::factory()->admin()->create(),
            User::factory()->manager()->create(),
            User::factory()->create(),
            User::factory()->stock()->create(),
        ];

        foreach ($clients as $user) {
            $this->deactivate($user)->assertForbidden();
            $this->actingAs($user)->post('/settings/activation', ['password' => 'password'])->assertForbidden();
        }

        $this->assertSame(Activation::ACTIVATED, Activation::status());
        $this->assertDatabaseMissing((new AuditLog)->getTable(), ['action' => 'deactivate']);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        Activation::deactivate($clients[0]);
    }

    public function test_signed_out_visitors_cannot_deactivate(): void
    {
        $this->install(Activation::ACTIVATED);
        User::factory()->admin()->create();

        $this->post(self::URL, ['password' => 'password', 'confirm' => '1'])->assertRedirect(route('login'));

        $this->assertSame(Activation::ACTIVATED, Activation::status());
    }

    // === Independence ===

    public function test_the_tier_and_the_activation_status_never_move_each_other(): void
    {
        $this->install(Activation::ACTIVATED);
        Setting::set('tier', 'standard', 'license');
        $vendor = $this->vendor();

        $this->deactivate($vendor);
        $this->assertSame('standard', License::tier());

        foreach (['premium', 'starter', 'standard'] as $tier) {
            $this->actingAs($vendor)->post('/settings/plan', ['tier' => $tier]);
            $this->assertSame($tier, License::tier());
            $this->assertSame(Activation::LOCKED, Activation::status());
            $this->assertSame(Activation::REASON_MANUAL, Activation::lockReason());
        }

        $this->actingAs($vendor)->post('/settings/activation', ['password' => 'password']);
        $this->assertSame('standard', License::tier());
        $this->assertSame(Activation::ACTIVATED, Activation::status());
    }

    // === Demo builds ===

    public function test_a_demo_build_refuses_the_route_and_hides_the_button(): void
    {
        $this->install(Activation::ACTIVATED);
        config(['pos.demo_mode' => true]);
        $vendor = $this->vendor();

        $this->deactivate($vendor)->assertSessionHasErrors('demo');
        $this->assertSame(Activation::ACTIVATED, Activation::status());
        $this->assertDatabaseMissing((new AuditLog)->getTable(), ['action' => 'deactivate']);

        $this->actingAs($vendor)->get('/settings?tab=plan')->assertOk()
            ->assertSee('Activation / Trial')
            ->assertDontSee('Deactivate (lock now)');
        $this->assertFalse(Activation::isLocked());
    }
}
