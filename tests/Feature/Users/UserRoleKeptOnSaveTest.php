<?php

namespace Tests\Feature\Users;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Saving the user edit form never changes a role the user did not pick (BUG-015), without
 * loosening who may assign what: a role that CHANGES must still be in the plan and within
 * the editor's privilege.
 */
class UserRoleKeptOnSaveTest extends TestCase
{
    use RefreshDatabase;

    private function payload(User $u, array $over = []): array
    {
        return $over + [
            'name' => $u->name, 'username' => $u->username, 'email' => $u->email,
            'role' => $u->role, 'language' => 'en', 'max_discount_pct' => 0, 'is_active' => 1,
        ];
    }

    public function test_an_unchanged_role_the_plan_no_longer_offers_is_kept_and_the_rest_of_the_form_saves(): void
    {
        Setting::set('tier', 'starter', 'license');
        $admin = User::factory()->admin()->create();
        $stock = User::factory()->stock()->create(['name' => 'Old Name']);

        $this->actingAs($admin)->put(route('users.update', $stock), $this->payload($stock, ['name' => 'New Name']))
            ->assertSessionHasNoErrors();

        $this->assertSame(User::ROLE_STOCK, $stock->fresh()->role);
        $this->assertSame('New Name', $stock->fresh()->name);
    }

    public function test_the_edit_form_keeps_the_current_role_selected_and_marks_it_not_in_plan(): void
    {
        Setting::set('tier', 'starter', 'license');
        $super = User::factory()->superAdmin()->create();
        $owner = User::factory()->admin()->create();

        $html = $this->actingAs($super)->get(route('users.edit', $owner))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('~<option value="admin" selected>[^<]*\(not in plan\)~', $html);
    }

    public function test_a_role_the_plan_does_not_offer_still_cannot_be_assigned_to_someone_else(): void
    {
        Setting::set('tier', 'starter', 'license');
        $super = User::factory()->superAdmin()->create();
        $cashier = User::factory()->create();

        foreach ([User::ROLE_STOCK, User::ROLE_ADMIN] as $role) {
            $this->actingAs($super)->put(route('users.update', $cashier), $this->payload($cashier, ['role' => $role]))
                ->assertSessionHasErrors('role');
        }

        $this->assertSame(User::ROLE_CASHIER, $cashier->fresh()->role);
    }

    public function test_an_admin_cannot_use_the_kept_role_allowance_to_reach_admin_tier(): void
    {
        $admin = User::factory()->admin()->create();
        $cashier = User::factory()->create();

        $this->actingAs($admin)->put(route('users.update', $cashier), $this->payload($cashier, ['role' => User::ROLE_ADMIN]))
            ->assertSessionHasErrors('role');
        $this->assertSame(User::ROLE_CASHIER, $cashier->fresh()->role);

        $other = User::factory()->admin()->create();
        $this->actingAs($admin)->put(route('users.update', $other), $this->payload($other))->assertForbidden();
    }
}
