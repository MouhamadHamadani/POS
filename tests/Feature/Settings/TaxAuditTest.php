<?php

namespace Tests\Feature\Settings;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** BUG-019: adding, editing and deleting a tax rate are audited with the rate before and after. */
class TaxAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_update_and_delete_each_leave_an_entry_with_the_rate(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('settings.tax.store'), ['name' => 'VAT', 'rate' => 0.11])->assertSessionHasNoErrors();
        $tax = Tax::sole();
        $create = AuditLog::where('action', 'create')->where('model_type', Tax::class)->sole();
        $this->assertSame($tax->id, $create->model_id);
        $this->assertNull($create->old_values);
        $this->assertEquals(0.11, $create->new_values['rate']);

        $this->actingAs($admin)->put(route('settings.tax.update', $tax), ['name' => 'VAT', 'rate' => 0.00, 'is_active' => '1'])->assertSessionHasNoErrors();
        $update = AuditLog::where('action', 'update')->where('model_type', Tax::class)->sole();
        $this->assertEquals(0.11, $update->old_values['rate']);
        $this->assertEquals(0.0, $update->new_values['rate']);

        $this->actingAs($admin)->delete(route('settings.tax.destroy', $tax))->assertSessionHasNoErrors();
        $delete = AuditLog::where('action', 'delete')->where('model_type', Tax::class)->sole();
        $this->assertSame($tax->id, $delete->model_id);
        $this->assertSame('VAT', $delete->old_values['name']);
        $this->assertNull($delete->new_values);
    }

    public function test_a_refused_delete_leaves_no_entry(): void
    {
        $tax = Tax::factory()->create();
        Product::factory()->create(['tax_id' => $tax->id]);

        $this->actingAs(User::factory()->admin()->create())->delete(route('settings.tax.destroy', $tax))->assertSessionHasErrors('delete');

        $this->assertSame(0, AuditLog::count());
        $this->assertNotNull($tax->fresh());
    }
}
