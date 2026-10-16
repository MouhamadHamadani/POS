<?php

namespace Tests\Feature\Client;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Support\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A client build is the same app with POS_CLIENT set. Unset must be exactly
 * the app that shipped before profiles existed; set must apply the profile.
 */
class ClientProfileTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE = 'zz-client-fixture';

    private array $valid = [
        'name' => 'Store Owner',
        'username' => 'owner',
        'password' => 'a-real-password',
        'password_confirmation' => 'a-real-password',
        'language' => 'en',
    ];

    protected function tearDown(): void
    {
        $dir = resource_path('clients/vortex/views');
        @unlink($dir.'/'.self::FIXTURE.'.blade.php');
        // Only succeeds for directories this test created; real ones stay.
        @rmdir($dir);
        @rmdir(dirname($dir));
        @rmdir(dirname($dir, 2));

        parent::tearDown();
    }

    public function test_with_no_client_the_app_is_plain_lebasouk(): void
    {
        $this->assertNull(config('pos.client'));
        $this->assertNull(Client::name());
        $this->assertFalse(Client::is('vortex'));
        $this->assertSame('fallback', Client::config('settings.general.business_name', 'fallback'));
        $this->assertSame('core.view', Client::view('prints.delivery-note', 'core.view'));
    }

    public function test_with_no_client_setup_seeds_nothing(): void
    {
        $this->post('/setup', $this->valid)->assertRedirect(route('dashboard'));

        $this->assertNull(Setting::get('business_name'));
        $this->assertNull(Setting::get('business_name_ar'));
        $this->assertNull(Setting::get('receipt_footer'));
    }

    public function test_vortex_profile_is_active_and_readable(): void
    {
        config(['pos.client' => 'vortex']);

        $this->assertSame('vortex', Client::name());
        $this->assertTrue(Client::is('vortex'));
        $this->assertFalse(Client::is('other'));
        $this->assertSame('Vortex Energy', Client::config('settings.general.business_name'));
    }

    public function test_vortex_setup_seeds_the_business_defaults(): void
    {
        config(['pos.client' => 'vortex']);

        $this->post('/setup', $this->valid)->assertRedirect(route('dashboard'));

        $this->assertSame('Vortex Energy', Setting::get('business_name'));
        $this->assertSame('', Setting::get('business_name_ar'));
        $this->assertSame('Vortex Energy — Powering the Future', Setting::get('receipt_footer'));
        $this->assertDatabaseHas('settings', ['key' => 'receipt_footer', 'group' => 'receipt']);
        $this->assertSame('vortex', AuditLog::where('action', 'setup')->firstOrFail()->new_values['client'] ?? null);
    }

    public function test_an_admin_edit_survives_because_seeding_only_runs_at_setup(): void
    {
        config(['pos.client' => 'vortex']);
        $this->post('/setup', $this->valid);

        Setting::set('business_name', 'Vortex Energy Tyre', 'general');

        // Setup is closed once an account exists, so nothing can re-seed.
        $this->post('/logout');
        $this->post('/setup', $this->valid)->assertNotFound();
        $this->assertSame('Vortex Energy Tyre', Setting::get('business_name'));
    }

    public function test_view_resolves_to_the_client_override_when_one_exists(): void
    {
        config(['pos.client' => 'vortex']);
        $dir = resource_path('clients/vortex/views');
        is_dir($dir) || mkdir($dir, 0777, true);
        file_put_contents($dir.'/'.self::FIXTURE.'.blade.php', 'client override');

        $this->assertSame('client::'.self::FIXTURE, Client::view(self::FIXTURE, 'core.view'));
        $this->assertSame('client override', view(Client::view(self::FIXTURE, 'core.view'))->render());
    }

    public function test_view_falls_back_to_core_when_the_client_has_no_override(): void
    {
        config(['pos.client' => 'vortex']);

        $this->assertSame('core.view', Client::view('no.such.override', 'core.view'));
    }

    public function test_an_unknown_client_degrades_to_core(): void
    {
        config(['pos.client' => 'nosuch']);

        $this->assertSame('x', Client::config('settings.general.business_name', 'x'));
        $this->assertSame('core.view', Client::view('prints.delivery-note', 'core.view'));
    }
}
