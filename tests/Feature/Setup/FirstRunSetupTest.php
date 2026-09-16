<?php

namespace Tests\Feature\Setup;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A shipped build carries no accounts. These pin the two halves of that:
 * the owner can provision the machine once, and nobody can do it twice.
 */
class FirstRunSetupTest extends TestCase
{
    use RefreshDatabase;

    private array $valid = [
        'name' => 'Mouhamad Hamadani',
        'username' => 'owner',
        'email' => 'owner@lebasouk.local',
        'password' => 'a-real-password',
        'password_confirmation' => 'a-real-password',
        'pin' => '4821',
        'language' => 'en',
    ];

    public function test_setup_screen_is_reachable_when_there_are_no_accounts(): void
    {
        $this->assertSame(0, User::count());

        $this->get('/setup')->assertOk()->assertSee('Set up this machine');
    }

    public function test_every_route_redirects_to_setup_until_an_account_exists(): void
    {
        foreach (['/', '/login', '/pos', '/dashboard', '/products'] as $route) {
            $this->get($route)->assertRedirect(route('setup.show'));
        }
    }

    public function test_setup_creates_a_super_admin_and_logs_them_in(): void
    {
        $response = $this->post('/setup', $this->valid);

        $response->assertRedirect(route('dashboard'));

        $user = User::where('username', 'owner')->firstOrFail();
        $this->assertSame(User::ROLE_SUPER_ADMIN, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_password_and_pin_are_hashed_not_stored_raw(): void
    {
        $this->post('/setup', $this->valid);

        $user = User::where('username', 'owner')->firstOrFail();

        $this->assertNotSame('a-real-password', $user->getAuthPassword());
        $this->assertTrue(password_verify('a-real-password', $user->getAuthPassword()));
        $this->assertNotSame('4821', $user->pin);
    }

    public function test_setup_is_recorded_in_the_audit_log(): void
    {
        $this->post('/setup', $this->valid);

        $user = User::where('username', 'owner')->firstOrFail();

        $this->assertDatabaseHas((new AuditLog)->getTable(), [
            'action' => 'setup',
            'model_id' => $user->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_setup_closes_permanently_once_an_account_exists(): void
    {
        $this->post('/setup', $this->valid);
        $this->post('/logout');

        $this->get('/setup')->assertNotFound();

        // And it cannot be used to mint a second super_admin.
        $this->post('/setup', array_merge($this->valid, [
            'username' => 'intruder',
            'email' => 'intruder@example.com',
        ]))->assertNotFound();

        $this->assertNull(User::where('username', 'intruder')->first());
        $this->assertSame(1, User::count());
    }

    public function test_a_short_password_is_rejected_and_no_account_is_created(): void
    {
        $this->post('/setup', array_merge($this->valid, [
            'password' => 'short',
            'password_confirmation' => 'short',
        ]))->assertSessionHasErrors('password');

        $this->assertSame(0, User::count());
    }

    public function test_a_mismatched_confirmation_is_rejected(): void
    {
        $this->post('/setup', array_merge($this->valid, [
            'password_confirmation' => 'something-else',
        ]))->assertSessionHasErrors('password');

        $this->assertSame(0, User::count());
    }

    public function test_a_non_numeric_pin_is_rejected(): void
    {
        $this->post('/setup', array_merge($this->valid, ['pin' => 'abcd']))
            ->assertSessionHasErrors('pin');

        $this->assertSame(0, User::count());
    }

    public function test_the_default_seeder_creates_config_but_no_accounts(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        $this->assertSame(0, User::count(), 'A shipped build must not contain a seeded credential.');
        $this->assertTrue(Setting::query()->exists());
    }
}
