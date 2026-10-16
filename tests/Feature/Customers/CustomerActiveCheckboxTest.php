<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\BugHunt\Concerns\SubmitsRenderedForms;
use Tests\TestCase;

/** The "Active" box on the customer form (BUG-002): ticked by default on create, and really switchable off. */
class CustomerActiveCheckboxTest extends TestCase
{
    use RefreshDatabase;
    use SubmitsRenderedForms;

    public function test_a_new_customer_is_active_unless_the_box_is_unticked(): void
    {
        $admin = User::factory()->admin()->create();
        $html = $this->actingAs($admin)->get(route('customers.create'))->assertOk()->getContent();

        $this->submitRenderedForm($html, route('customers.store'), set: ['name' => 'Active Ali'])->assertSessionHasNoErrors();
        $this->submitRenderedForm($html, route('customers.store'), uncheck: ['is_active'], set: ['name' => 'Retired Rima'])->assertSessionHasNoErrors();

        $this->assertTrue(Customer::where('name', 'Active Ali')->firstOrFail()->is_active);
        $this->assertFalse(Customer::where('name', 'Retired Rima')->firstOrFail()->is_active);
    }

    public function test_an_inactive_customer_can_be_reactivated_from_the_edit_form(): void
    {
        $customer = Customer::create(['name' => 'Rami', 'customer_group' => 'retail', 'is_active' => false, 'credit_limit' => 0]);
        $admin = User::factory()->admin()->create();
        $html = $this->actingAs($admin)->get(route('customers.edit', $customer))->assertOk()->getContent();

        // Tick the box: the page renders it unchecked, so add the field the browser would send.
        $payload = $this->browserPayload($html, route('customers.update', $customer), set: ['is_active' => '1']);
        $this->call('PUT', route('customers.update', $customer), $payload)->assertSessionHasNoErrors();

        $this->assertTrue($customer->fresh()->is_active);
    }
}
