<?php

namespace Tests\Feature\Users;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Support\License;
use App\Support\SuperAdmin;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The client's `admin` exists on every plan, Starter included. Only the vendor's super_admin
 * creates admin accounts; an admin runs the staff under them.
 */
class AdminRoleTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'vendor-pre-agreed-pass';

    private function payload(string $username, string $role): array
    {
        return [
            'name' => ucfirst($username), 'username' => $username, 'password' => 'secret123',
            'password_confirmation' => 'secret123', 'role' => $role, 'language' => 'en', 'is_active' => 1,
        ];
    }

    /** @return array<string, array{string}> */
    public static function plans(): array
    {
        return ['starter' => ['starter'], 'standard' => ['standard'], 'premium' => ['premium']];
    }

    #[DataProvider('plans')]
    public function test_every_plan_lets_the_admin_role_exist(string $plan): void
    {
        Setting::set('tier', $plan, 'license');

        $this->assertContains(User::ROLE_ADMIN, License::allowedRoles());
    }

    #[DataProvider('plans')]
    public function test_the_super_admin_can_assign_the_admin_role_on_every_plan(string $plan): void
    {
        Setting::set('tier', $plan, 'license');
        $super = User::factory()->superAdmin()->create();

        $this->actingAs($super)->get(route('users.create'))->assertOk()->assertSee('value="admin"', false);
        $this->actingAs($super)->post(route('users.store'), $this->payload('owner', User::ROLE_ADMIN))
            ->assertRedirect(route('users.index'))->assertSessionHasNoErrors();

        $this->assertSame(User::ROLE_ADMIN, User::where('username', 'owner')->sole()->role);
        $this->assertTrue(AuditLog::where('action', 'create')->where('model_type', User::class)->exists());
    }

    #[DataProvider('plans')]
    public function test_an_admin_cannot_create_an_admin_on_any_plan(string $plan): void
    {
        Setting::set('tier', $plan, 'license');
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('users.store'), $this->payload('second_owner', User::ROLE_ADMIN))
            ->assertSessionHasErrors('role');
        $this->actingAs($admin)->post(route('users.store'), $this->payload('boss', User::ROLE_SUPER_ADMIN))
            ->assertSessionHasErrors('role');

        $this->assertDatabaseMissing('users', ['username' => 'second_owner']);
        $this->assertDatabaseMissing('users', ['username' => 'boss']);
    }

    public function test_an_admin_manages_manager_cashier_and_stock_users(): void
    {
        Setting::set('tier', 'premium', 'license');       // Standard's 3 seats would stop the fourth account, the owner's own
        $admin = User::factory()->admin()->create();

        foreach ([User::ROLE_MANAGER, User::ROLE_CASHIER, User::ROLE_STOCK] as $role) {
            $this->actingAs($admin)->post(route('users.store'), $this->payload("{$role}_user", $role))
                ->assertSessionHasNoErrors();
        }
        $this->assertSame(3, User::whereIn('role', [User::ROLE_MANAGER, User::ROLE_CASHIER, User::ROLE_STOCK])->count());

        $cashier = User::where('username', 'cashier_user')->sole();
        $this->actingAs($admin)->put(route('users.update', $cashier), [
            'name' => 'Renamed', 'username' => $cashier->username, 'role' => User::ROLE_MANAGER,
            'language' => 'en', 'max_discount_pct' => 0, 'is_active' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertSame(User::ROLE_MANAGER, $cashier->fresh()->role);

        // ...but never up to admin tier.
        $this->actingAs($admin)->put(route('users.update', $cashier), [
            'name' => 'Renamed', 'username' => $cashier->username, 'role' => User::ROLE_ADMIN,
            'language' => 'en', 'max_discount_pct' => 0, 'is_active' => 1,
        ])->assertSessionHasErrors('role');
        $this->assertSame(User::ROLE_MANAGER, $cashier->fresh()->role);
    }

    public function test_an_admin_cannot_touch_another_admin_tier_account(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->admin()->create();
        $vendor = User::factory()->superAdmin()->create();

        foreach ([$other, $vendor] as $target) {
            $this->actingAs($admin)->get(route('users.edit', $target))->assertForbidden();
            $this->actingAs($admin)->post(route('users.toggle', $target))->assertForbidden();
            $this->actingAs($admin)->post(route('users.reset-password', $target), ['password' => 'secret123', 'password_confirmation' => 'secret123'])->assertForbidden();
            $this->actingAs($admin)->post(route('users.reset-pin', $target), ['pin' => '1234'])->assertForbidden();
        }
    }

    public function test_manager_cashier_and_stock_have_no_users_screen(): void
    {
        foreach ([User::ROLE_MANAGER, User::ROLE_CASHIER, User::ROLE_STOCK] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->get(route('users.index'))->assertForbidden();
            $this->actingAs($user)->post(route('users.store'), $this->payload('x'.$role, User::ROLE_CASHIER))->assertForbidden();
        }
    }

    public function test_starter_seats_are_two_and_the_admin_takes_one(): void
    {
        Setting::set('tier', 'starter', 'license');
        $super = User::factory()->superAdmin()->create();

        $this->assertSame(2, License::maxUsers());

        $this->actingAs($super)->post(route('users.store'), $this->payload('owner', User::ROLE_ADMIN))->assertSessionHasNoErrors();
        $this->assertSame(1, License::seatsUsed());
        $this->actingAs($super)->post(route('users.store'), $this->payload('till', User::ROLE_CASHIER))->assertSessionHasNoErrors();
        $this->assertTrue(License::isAtUserLimit());
        $this->actingAs($super)->post(route('users.store'), $this->payload('extra', User::ROLE_CASHIER))->assertSessionHasErrors('role');
    }

    public function test_the_plan_tab_names_the_roles_the_plan_includes(): void
    {
        Setting::set('tier', 'starter', 'license');
        $super = User::factory()->superAdmin()->create();

        $this->actingAs($super)->get('/settings?tab=plan')->assertOk()
            ->assertSee('Roles in this plan')->assertSee('Admin')->assertSee('The client&#039;s admin account is.', false);
    }

    // ---- a fresh install ------------------------------------------------------------

    public function test_on_a_fresh_install_the_vendor_creates_the_first_client_admin_who_then_runs_the_staff(): void
    {
        config([
            'pos.demo_mode' => false,
            'pos.super_admin.username' => 'buildsyntax',
            'pos.super_admin.name' => 'Build Syntax Support',
            'pos.super_admin.password_hash' => Hash::make(self::PASSWORD),
        ]);

        // The seeder gives the install its settings and the built-in super_admin; a reset wipes the accounts
        // again, and the next launch (ensure) brings the vendor back on Starter.
        $this->seed(DatabaseSeeder::class);
        $this->artisan('pos:reset-data --force')->assertSuccessful();
        $this->assertSame(0, User::count());
        SuperAdmin::ensure();
        $this->assertSame('starter', License::tier());

        $this->post('/login', ['login' => 'buildsyntax', 'password' => self::PASSWORD])->assertRedirect('/pos');
        $this->post(route('users.store'), $this->payload('shopowner', User::ROLE_ADMIN))->assertRedirect(route('users.index'));
        $this->post('/logout');

        $owner = User::where('username', 'shopowner')->sole();
        $this->assertSame(1, License::seatsUsed());

        $this->actingAs($owner)->post(route('users.store'), $this->payload('till1', User::ROLE_CASHIER))->assertRedirect(route('users.index'));
        $this->actingAs($owner)->get(route('settings.index'))->assertOk();
        $this->assertSame(2, License::seatsUsed());
    }
}
