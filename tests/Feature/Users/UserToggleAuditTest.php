<?php

namespace Tests\Feature\Users;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** BUG-018: Activate / Deactivate on the users list is audited with the before and after state. */
class UserToggleAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_entry_records_who_and_the_old_and_new_state(): void
    {
        $admin = User::factory()->admin()->create();
        $cashier = User::factory()->create();

        $this->actingAs($admin)->post(route('users.toggle', $cashier))->assertSessionHasNoErrors();

        $log = AuditLog::where('model_type', User::class)->where('model_id', $cashier->id)->sole();
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame('update', $log->action);
        $this->assertSame(['is_active' => true], $log->old_values);
        $this->assertSame(['is_active' => false], $log->new_values);
    }

    public function test_a_refused_toggle_leaves_no_entry(): void
    {
        $owner = User::factory()->superAdmin()->create();

        $this->actingAs($owner)->post(route('users.toggle', $owner))->assertSessionHasErrors('active'); // cannot switch yourself off

        $this->assertSame(0, AuditLog::count());
        $this->assertTrue($owner->fresh()->is_active);
    }
}
