<?php

namespace Tests\Feature\Users;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The two-tier admin split: `super_admin` is the vendor/owner account
 * (backups, provisioning admin-tier accounts); `admin` is the client's own
 * store owner and manages staff only.
 */
class RolePrivilegeTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function staffPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'New Person',
            'username' => 'newperson',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'role' => User::ROLE_CASHIER,
            'language' => 'en',
            'is_active' => 1,
        ], $overrides);
    }

    // --- Backups: super-admin only -------------------------------------

    public function test_admin_cannot_reach_backup_routes(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/settings/backup/now')->assertForbidden();
        $this->actingAs($admin)->get('/settings/backup/download/pos-backup.sqlite')->assertForbidden();
        $this->actingAs($admin)->post('/settings/backup/restore/pos-backup.sqlite')->assertForbidden();
        $this->actingAs($admin)->delete('/settings/backup/pos-backup.sqlite')->assertForbidden();
    }

    public function test_super_admin_can_reach_backup_routes(): void
    {
        $super = $this->superAdmin();

        // Not 403: the route is reachable. A missing file redirects back with
        // an error, which is the controller's business, not the gate's.
        $this->actingAs($super)->get('/settings/backup/download/missing.sqlite')
            ->assertRedirect();
        $this->actingAs($super)->delete('/settings/backup/missing.sqlite')
            ->assertRedirect();
    }

    public function test_backup_tab_is_hidden_from_admin_and_shown_to_super_admin(): void
    {
        $this->actingAs($this->admin())->get('/settings?tab=backup')
            ->assertOk()
            ->assertDontSee('Backup Now');

        $this->actingAs($this->superAdmin())->get('/settings?tab=backup')
            ->assertOk()
            ->assertSee('Backup Now');
    }

    // --- Privilege escalation ------------------------------------------

    public function test_admin_cannot_create_an_admin_or_super_admin(): void
    {
        $admin = $this->admin();

        foreach ([User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN] as $role) {
            $this->actingAs($admin)
                ->post('/users', $this->staffPayload(['role' => $role, 'username' => "esc_{$role}"]))
                ->assertSessionHasErrors('role');

            $this->assertDatabaseMissing('users', ['username' => "esc_{$role}"]);
        }
    }

    public function test_admin_cannot_edit_an_admin_tier_account(): void
    {
        $admin = $this->admin();
        $otherAdmin = User::factory()->admin()->create(['username' => 'other_admin']);
        $super = $this->superAdmin();

        foreach ([$otherAdmin, $super] as $target) {
            $this->actingAs($admin)->get("/users/{$target->id}/edit")->assertForbidden();

            $this->actingAs($admin)->put("/users/{$target->id}", [
                'name' => 'Hijacked',
                'username' => $target->username,
                'role' => User::ROLE_CASHIER,
                'language' => 'en',
                'is_active' => 1,
            ])->assertForbidden();

            $this->actingAs($admin)->post("/users/{$target->id}/reset-password", [
                'password' => 'hijacked1',
                'password_confirmation' => 'hijacked1',
            ])->assertForbidden();

            $this->actingAs($admin)->post("/users/{$target->id}/reset-pin", ['pin' => '9999'])
                ->assertForbidden();

            $this->actingAs($admin)->post("/users/{$target->id}/toggle")->assertForbidden();

            $this->assertNotSame('Hijacked', $target->fresh()->name);
        }
    }

    public function test_admin_can_still_manage_staff_accounts(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/users', $this->staffPayload())
            ->assertRedirect(route('users.index'));

        $staff = User::where('username', 'newperson')->firstOrFail();
        $this->assertSame(User::ROLE_CASHIER, $staff->role);

        $this->actingAs($admin)->get("/users/{$staff->id}/edit")->assertOk();
        $this->actingAs($admin)->post("/users/{$staff->id}/toggle")->assertRedirect();
    }

    public function test_admin_role_select_offers_staff_roles_only(): void
    {
        $this->actingAs($this->admin())->get('/users/create')
            ->assertOk()
            ->assertDontSee('value="admin"', false)
            ->assertDontSee('value="super_admin"', false)
            ->assertSee('value="cashier"', false);

        $this->actingAs($this->superAdmin())->get('/users/create')
            ->assertOk()
            ->assertSee('value="admin"', false)
            ->assertSee('value="super_admin"', false);
    }

    public function test_super_admin_can_create_and_edit_admin_accounts(): void
    {
        $super = $this->superAdmin();

        $this->actingAs($super)
            ->post('/users', $this->staffPayload(['username' => 'clientowner', 'role' => User::ROLE_ADMIN]))
            ->assertRedirect(route('users.index'));

        $clientAdmin = User::where('username', 'clientowner')->firstOrFail();
        $this->assertSame(User::ROLE_ADMIN, $clientAdmin->role);

        $this->actingAs($super)->get("/users/{$clientAdmin->id}/edit")->assertOk();

        $this->actingAs($super)->put("/users/{$clientAdmin->id}", [
            'name' => 'Client Owner',
            'username' => 'clientowner',
            'role' => User::ROLE_ADMIN,
            'language' => 'en',
            'is_active' => 1,
        ])->assertRedirect(route('users.index'));

        $this->assertSame('Client Owner', $clientAdmin->fresh()->name);
    }

    public function test_super_admin_can_edit_their_own_account_without_being_demoted(): void
    {
        $super = $this->superAdmin();

        $this->actingAs($super)->put("/users/{$super->id}", [
            'name' => 'Vendor Owner',
            'username' => $super->username,
            'role' => User::ROLE_SUPER_ADMIN,
            'language' => 'en',
            'is_active' => 1,
        ])->assertRedirect(route('users.index'));

        $super->refresh();
        $this->assertSame('Vendor Owner', $super->name);
        $this->assertSame(User::ROLE_SUPER_ADMIN, $super->role);
    }

    // --- super_admin is a superset; other roles are unchanged -----------

    public function test_super_admin_passes_every_role_gated_route(): void
    {
        $super = $this->superAdmin();

        foreach (['/products', '/categories', '/suppliers', '/purchase-orders',
                  '/customers', '/reports', '/users', '/settings'] as $path) {
            $this->actingAs($super)->get($path)->assertOk();
        }
    }

    public function test_staff_roles_are_unaffected_by_the_split(): void
    {
        $cashier = User::factory()->create(['role' => User::ROLE_CASHIER]);
        $manager = User::factory()->manager()->create();
        $stock = User::factory()->create(['role' => User::ROLE_STOCK]);

        // cashier: no products, no reports, no users
        $this->actingAs($cashier)->get('/products')->assertForbidden();
        $this->actingAs($cashier)->get('/reports')->assertForbidden();
        $this->actingAs($cashier)->get('/users')->assertForbidden();

        // manager: reports + customers yes, users/settings no
        $this->actingAs($manager)->get('/reports')->assertOk();
        $this->actingAs($manager)->get('/customers')->assertOk();
        $this->actingAs($manager)->get('/users')->assertForbidden();
        $this->actingAs($manager)->get('/settings')->assertForbidden();

        // stock: products yes, reports/users no
        $this->actingAs($stock)->get('/products')->assertOk();
        $this->actingAs($stock)->get('/reports')->assertForbidden();
        $this->actingAs($stock)->get('/users')->assertForbidden();

        // and none of them can touch backups
        foreach ([$cashier, $manager, $stock] as $user) {
            $this->actingAs($user)->post('/settings/backup/now')->assertForbidden();
        }
    }

    /**
     * The first-run account is no longer seeded — a shipped build carries no
     * credential at all, and the owner provisions the machine through /setup.
     * That flow is covered by Tests\Feature\Setup\FirstRunSetupTest; what
     * matters here is that the seeders leave no account behind.
     */
    public function test_the_seeders_create_no_account_at_all(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        $this->assertSame(0, User::count());
    }
}
