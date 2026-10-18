<?php

namespace Tests\Feature\BugHunt;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\BugHunt\Concerns\SubmitsRenderedForms;
use Tests\TestCase;

/**
 * BUG-015: saving the user edit form silently changes the user's role when their role is not
 * offered by the install's plan.
 *
 * The Role <select> lists only the roles the current tier allows (Starter: manager, cashier,
 * plus super_admin for a super_admin editor). A user whose role is outside that list — the
 * normal state after the vendor lowers a tier on the Plan tab — has no selected option, so the
 * browser submits the FIRST option. For a super_admin editor the first option is "Super Admin":
 * renaming the client's admin, or just pressing Save, promotes them to super_admin (the vendor
 * account, which also escapes the seat count and the backup/plan gates).
 * For an admin editor the first option is "Manager" (a stock keeper becomes a manager).
 */
class Bug015UserEditFormChangesRoleWhenRoleNotInPlanTest extends TestCase
{
    use RefreshDatabase;
    use SubmitsRenderedForms;

    public function test_saving_the_edit_form_does_not_change_the_role_of_a_user_the_plan_no_longer_offers(): void
    {
        Setting::set('tier', 'starter', 'license');
        $super = User::factory()->superAdmin()->create();
        $owner = User::factory()->admin()->create(['username' => 'storeowner']);

        $html = $this->actingAs($super)->get(route('users.edit', $owner))->assertOk()->getContent();

        $this->submitRenderedForm($html, route('users.update', $owner));

        $role = $owner->fresh()->role;
        $this->assertSame(User::ROLE_ADMIN, $role, "Pressing Save changed the user's role to {$role}.");
    }
}
