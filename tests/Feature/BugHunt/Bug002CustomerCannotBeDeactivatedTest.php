<?php

namespace Tests\Feature\BugHunt;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\BugHunt\Concerns\SubmitsRenderedForms;
use Tests\TestCase;

/**
 * BUG-002: unticking "Active" on the customer edit form does not deactivate the customer.
 *
 * The browser omits an unchecked checkbox, and CustomerController::validateData()
 * reads it with `boolean('is_active', true)` — absent means active. The same
 * method already reads `tax_exempt` with no default, which is why that box works.
 * Inactive customers are what the till's customer search hides.
 */
class Bug002CustomerCannotBeDeactivatedTest extends TestCase
{
    use RefreshDatabase;
    use SubmitsRenderedForms;

    public function test_unticking_active_on_the_edit_form_and_saving_deactivates_the_customer(): void
    {
        $customer = Customer::create(['name' => 'Rami', 'customer_group' => 'retail', 'is_active' => true, 'credit_limit' => 0]);
        $admin = User::factory()->admin()->create();

        $html = $this->actingAs($admin)->get(route('customers.edit', $customer))->assertOk()->getContent();

        $this->submitRenderedForm($html, route('customers.update', $customer), uncheck: ['is_active'])
            ->assertSessionHasNoErrors();

        $this->assertFalse($customer->fresh()->is_active, 'The customer stayed active after the box was unticked.');
    }
}
