<?php

namespace Tests\Feature\BugHunt;

use App\Models\AuditLog;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BUG-019: adding, editing or deleting a tax rate (Settings > Tax) leaves no audit-log entry.
 *
 * SettingController::update() audits the other settings tabs ('settings_update'), but
 * storeTax(), updateTax() and destroyTax() never call AuditLog::record(). A change of the
 * VAT rate changes every sale's tax from that moment on, and nothing records who did it.
 */
class Bug019TaxRateChangesAreNotAuditedTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_tax_rate_is_audited(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('settings.tax.store'), ['name' => 'VAT', 'rate' => 0.11])
            ->assertSessionHasNoErrors();

        $this->assertGreaterThanOrEqual(1, AuditLog::where('user_id', $admin->id)->count(), 'Creating a tax rate left no audit-log entry.');
    }

    public function test_changing_a_tax_rate_is_audited(): void
    {
        $admin = User::factory()->admin()->create();
        $tax = Tax::factory()->create(['rate' => 0.11]);

        $this->actingAs($admin)->put(route('settings.tax.update', $tax), [
            'name' => 'VAT', 'rate' => 0.00, 'is_active' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertGreaterThanOrEqual(1, AuditLog::where('user_id', $admin->id)->count(), 'Changing a tax rate left no audit-log entry.');
    }

    public function test_deleting_a_tax_rate_is_audited(): void
    {
        $admin = User::factory()->admin()->create();
        $tax = Tax::factory()->create();

        $this->actingAs($admin)->delete(route('settings.tax.destroy', $tax))->assertSessionHasNoErrors();

        $this->assertGreaterThanOrEqual(1, AuditLog::where('user_id', $admin->id)->count(), 'Deleting a tax rate left no audit-log entry.');
    }
}
