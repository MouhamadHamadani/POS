<?php

namespace Tests\Feature\BugHunt;

use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\BugHunt\Concerns\SubmitsRenderedForms;
use Tests\TestCase;

/**
 * BUG-003: unticking "Active" on the supplier edit form does not deactivate the supplier.
 *
 * SupplierController::validateData() reads the checkbox with `boolean('is_active', true)`,
 * so the browser's "box unticked = field absent" means "stay active". Inactive suppliers
 * are what the purchase-order form's supplier picker hides.
 */
class Bug003SupplierCannotBeDeactivatedTest extends TestCase
{
    use RefreshDatabase;
    use SubmitsRenderedForms;

    public function test_unticking_active_on_the_edit_form_and_saving_deactivates_the_supplier(): void
    {
        $supplier = Supplier::create(['name' => 'Acme Foods', 'payment_terms' => 'NET30', 'is_active' => true]);
        $admin = User::factory()->admin()->create();

        $html = $this->actingAs($admin)->get(route('suppliers.edit', $supplier))->assertOk()->getContent();

        $this->submitRenderedForm($html, route('suppliers.update', $supplier), uncheck: ['is_active'])
            ->assertSessionHasNoErrors();

        $this->assertFalse($supplier->fresh()->is_active, 'The supplier stayed active after the box was unticked.');
    }
}
