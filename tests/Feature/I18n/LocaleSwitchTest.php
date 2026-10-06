<?php

namespace Tests\Feature\I18n;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who decides the language: the signed-in user, else this browser session,
 * else the store default. A guest must be able to switch before logging in.
 */
class LocaleSwitchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // RequiresSetup funnels everything to /setup until an account exists.
        User::factory()->create(['language' => 'en']);
    }

    public function test_guest_switch_stores_the_choice_in_the_session(): void
    {
        $this->get('/locale/ar')->assertRedirect()->assertSessionHas('locale', 'ar');
    }

    public function test_unknown_locale_is_a_404(): void
    {
        $this->get('/locale/fr')->assertNotFound();
    }

    public function test_login_page_follows_the_session_choice(): void
    {
        $this->withSession(['locale' => 'ar'])->get('/login')
            ->assertOk()
            ->assertSee('<html lang="ar" dir="rtl">', false)
            ->assertSee('تسجيل الدخول')
            ->assertDontSee('Remember me');
    }

    public function test_login_page_is_english_ltr_by_default(): void
    {
        $this->get('/login')->assertSee('<html lang="en" dir="ltr">', false)->assertSee('Remember me');
    }

    public function test_the_login_page_offers_the_switch_to_guests(): void
    {
        $this->get('/login')->assertSee(route('locale.switch', 'ar'), false)->assertSee('عربي');
    }

    public function test_store_default_applies_to_guests_with_no_session(): void
    {
        Setting::set('language', 'ar', 'appearance');

        $this->get('/login')->assertSee('<html lang="ar" dir="rtl">', false)->assertSee('تسجيل الدخول');
    }

    public function test_the_session_choice_beats_the_store_default(): void
    {
        Setting::set('language', 'ar', 'appearance');

        $this->withSession(['locale' => 'en'])->get('/login')->assertSee('Remember me');
    }

    public function test_signed_in_switch_persists_on_the_user_and_is_audited(): void
    {
        $admin = User::factory()->admin()->create(['language' => 'en']);

        $this->actingAs($admin)->get('/locale/ar')->assertRedirect();

        $this->assertSame('ar', $admin->fresh()->language);

        $log = AuditLog::where('user_id', $admin->id)->where('action', 'update')->latest('id')->firstOrFail();
        $this->assertSame(['language' => 'en'], $log->old_values);
        $this->assertSame(['language' => 'ar'], $log->new_values);
    }

    public function test_switching_to_the_current_language_writes_no_audit_row(): void
    {
        $admin = User::factory()->admin()->create(['language' => 'ar']);

        $this->actingAs($admin)->get('/locale/ar');

        $this->assertSame(0, AuditLog::where('user_id', $admin->id)->count());
    }

    public function test_a_users_own_language_beats_the_store_default(): void
    {
        Setting::set('language', 'ar', 'appearance');
        $admin = User::factory()->admin()->create(['language' => 'en']);

        $this->actingAs($admin)->get('/users/create')->assertOk()->assertSee('<html lang="en" dir="ltr">', false);
    }

    public function test_new_user_form_defaults_to_the_store_language(): void
    {
        Setting::set('language', 'ar', 'appearance');
        $admin = User::factory()->admin()->create(['language' => 'en']);

        $this->actingAs($admin)->get('/users/create')
            ->assertSee('<option value="ar" selected>', false);
    }

    public function test_first_run_setup_seeds_the_store_language(): void
    {
        User::query()->delete();

        $this->post('/setup', [
            'name' => 'Owner', 'username' => 'owner', 'password' => 'a-real-password',
            'password_confirmation' => 'a-real-password', 'language' => 'ar',
        ])->assertRedirect(route('dashboard'));

        $this->assertSame('ar', Setting::get('language'));
    }

    public function test_the_setup_screen_can_switch_language_before_any_account_exists(): void
    {
        User::query()->delete();

        $this->get('/locale/ar')->assertRedirect()->assertSessionHas('locale', 'ar');
        $this->withSession(['locale' => 'ar'])->get('/setup')->assertOk()->assertSee('dir="rtl"', false);
    }
}
