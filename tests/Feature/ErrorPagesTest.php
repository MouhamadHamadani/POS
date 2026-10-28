<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The desktop window has no address bar: a failed page must always offer a way home, and an
 * expired login token must never need the app to be closed and reopened.
 */
class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    /** The CSRF check is skipped under PHPUnit; these tests want the real one. */
    private function enforceCsrf(): void
    {
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app->make('encrypter')) extends ValidateCsrfToken {
            protected function runningUnitTests()
            {
                return false;
            }
        });
    }

    // ---- 419 -----------------------------------------------------------------------------

    public function test_an_expired_token_on_the_login_form_returns_to_the_login_page_with_a_notice(): void
    {
        $this->enforceCsrf();
        User::factory()->create(['username' => 'till1', 'password' => 'secret123']);

        $this->post('/login', ['login' => 'till1', 'password' => 'secret123'])
            ->assertRedirect(route('login'))
            ->assertSessionHas('expired', __('errors.session_expired'));

        // Following it: the login page, the notice, the typed username kept, the password not.
        $this->followingRedirects()->post('/login', ['login' => 'till1', 'password' => 'secret123'])
            ->assertOk()
            ->assertSee('Your session expired, so the page was refreshed. Please sign in again.')
            ->assertSee('value="till1"', false)
            ->assertDontSee('secret123');
        $this->assertGuest();
    }

    public function test_after_the_notice_the_next_attempt_signs_in(): void
    {
        $this->enforceCsrf();
        User::factory()->create(['username' => 'till1', 'password' => 'secret123']);

        $this->post('/login', ['login' => 'till1', 'password' => 'secret123'])->assertRedirect(route('login'));
        $page = $this->get('/login')->assertOk()->getContent();
        preg_match('/name="_token" value="([^"]+)"/', $page, $m);

        $this->post('/login', ['_token' => $m[1], 'login' => 'till1', 'password' => 'secret123'])->assertRedirect('/pos');
        $this->assertAuthenticated();
    }

    public function test_the_notice_is_arabic_for_an_arabic_store(): void
    {
        $this->enforceCsrf();
        Setting::set('language', 'ar', 'general');

        $this->post('/login', ['login' => 'x', 'password' => 'y'])
            ->assertSessionHas('expired', 'انتهت جلستك فتم تحديث الصفحة. الرجاء تسجيل الدخول مجدداً.');
    }

    public function test_a_signed_out_logout_with_a_dead_token_also_lands_on_login(): void
    {
        $this->enforceCsrf();

        $this->post('/logout')->assertRedirect(route('login'))->assertSessionHas('expired');
    }

    public function test_fetch_requests_get_a_json_419_in_the_users_language(): void
    {
        $this->enforceCsrf();

        $this->postJson('/pos/api/sales', [])->assertStatus(419)
            ->assertExactJson(['message' => __('errors.session_expired_json'), 'error' => __('errors.session_expired_json')]);
    }

    public function test_a_signed_in_user_with_a_wrong_token_sees_the_error_page_with_a_way_home(): void
    {
        $this->enforceCsrf();

        $this->actingAs(User::factory()->manager()->create())->post('/expenses', [])
            ->assertStatus(419)
            ->assertSee('Error')->assertSee('419')->assertSee('Page expired')
            ->assertSee('security token expired')
            ->assertSee('Go home')->assertSee('href="'.url('/').'"', false)
            ->assertSee('aria-label="LebaSouk"', false)->assertSee('LebaSouk');
    }

    public function test_the_login_page_refreshes_itself_and_shows_no_notice_normally(): void
    {
        User::factory()->create();                               // with no account at all the page carries its own alert
        $this->get('/login')->assertOk()
            ->assertSee('location.reload()', false)->assertSee("e.persisted", false)
            ->assertSee('* 60000', false)
            ->assertDontSee('role="alert"', false);
    }

    // ---- the other pages ------------------------------------------------------------------

    public function test_an_unknown_address_gets_the_branded_404_in_the_stores_language(): void
    {
        $this->get('/no/such/page')->assertNotFound()
            ->assertSee('Page not found')->assertSee('Go home')->assertSee('aria-label="LebaSouk"', false);

        Setting::set('language', 'ar', 'general');
        $this->get('/no/such/page')->assertNotFound()
            ->assertSee('الصفحة غير موجودة')->assertSee('الصفحة الرئيسية')->assertSee('dir="rtl"', false);
    }

    public function test_a_forbidden_page_says_so_and_a_message_the_app_wrote_is_kept(): void
    {
        $cashier = User::factory()->create();
        $this->actingAs($cashier)->get('/users')->assertForbidden()
            ->assertSee('Access denied')->assertSee('You don&#039;t have permission to open this page.', false)->assertSee('Go home');

        $admin = User::factory()->admin()->create();
        $other = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('users.edit', $other))->assertForbidden()
            ->assertSee('Only a super admin can manage admin accounts.');
    }

    public function test_a_server_error_hides_the_exception_and_offers_the_way_home(): void
    {
        config(['app.debug' => false]);
        Route::middleware('web')->get('/_probe/boom', fn () => throw new \RuntimeException('SECRET-INTERNAL-DETAIL'));

        $this->get('/_probe/boom')->assertStatus(500)
            ->assertSee('Something went wrong')->assertSee('500')->assertSee('Go home')
            ->assertDontSee('SECRET-INTERNAL-DETAIL');
    }

    public function test_development_keeps_its_stack_trace(): void
    {
        config(['app.debug' => true]);
        Route::middleware('web')->get('/_probe/boom', fn () => throw new \RuntimeException('SECRET-INTERNAL-DETAIL'));

        $this->get('/_probe/boom')->assertStatus(500)->assertSee('SECRET-INTERNAL-DETAIL')->assertDontSee('id="go-back"', false);
    }

    public function test_any_other_status_gets_the_generic_page_and_retry_after_survives(): void
    {
        Route::middleware('web')->get('/_probe/slow', fn () => abort(429, '', ['Retry-After' => '30']));
        Route::middleware('web')->get('/_probe/teapot', fn () => abort(418));

        $this->get('/_probe/slow')->assertStatus(429)->assertSee('Too many requests')->assertHeader('Retry-After', '30');
        $this->get('/_probe/teapot')->assertStatus(418)->assertSee('Something went wrong')->assertSee('418')->assertSee('Go home');
    }

    public function test_json_errors_stay_json(): void
    {
        $this->getJson('/no/such/page')->assertNotFound()->assertJsonStructure(['message']);
        $this->actingAs(User::factory()->create())->getJson('/users')->assertForbidden()->assertJson(['error' => __('messages.forbidden')]);
    }

    public function test_the_page_needs_nothing_from_the_build_so_it_renders_when_the_app_is_what_broke(): void
    {
        $html = $this->get('/no/such/page')->getContent();

        $this->assertStringNotContainsString('/build/assets', $html);
        $this->assertStringContainsString("font-family: 'Cairo'", $html);
    }
}
