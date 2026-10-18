<?php

namespace Tests\Feature\BugHunt;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BUG-018: the Activate / Deactivate button on the users list leaves no audit-log entry.
 *
 * User management is audited everywhere else in UserController (create, update, reset
 * password, reset PIN); toggleActive() is the only mutation that is not. Deactivating
 * a cashier blocks their login, so "who turned this account off, and when" is exactly
 * what the audit log is for.
 */
class Bug018UserActivateDeactivateIsNotAuditedTest extends TestCase
{
    use RefreshDatabase;

    public function test_deactivating_and_reactivating_a_user_are_audited(): void
    {
        $admin = User::factory()->admin()->create();
        $cashier = User::factory()->create();

        $this->actingAs($admin)->post(route('users.toggle', $cashier))->assertSessionHasNoErrors();
        $this->assertFalse($cashier->fresh()->is_active);
        $afterDeactivate = AuditLog::where('user_id', $admin->id)->count();
        $this->assertGreaterThanOrEqual(1, $afterDeactivate, 'Deactivating a user left no audit-log entry.');

        $this->actingAs($admin)->post(route('users.toggle', $cashier))->assertSessionHasNoErrors();
        $this->assertTrue($cashier->fresh()->is_active);
        $this->assertGreaterThan($afterDeactivate, AuditLog::where('user_id', $admin->id)->count(), 'Reactivating a user left no audit-log entry.');
    }
}
