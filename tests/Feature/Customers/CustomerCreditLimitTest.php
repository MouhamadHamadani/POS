<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** BUG-008: a blank credit limit is "no credit" (0); a request that leaves the field out keeps the stored limit. */
class CustomerCreditLimitTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): Customer
    {
        return Customer::create(['name' => 'Rami', 'customer_group' => 'retail', 'is_active' => true, 'credit_limit' => 250]);
    }

    public function test_a_blank_credit_limit_is_saved_as_zero(): void
    {
        $customer = $this->customer();

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('customers.update', $customer), ['name' => 'Rami', 'customer_group' => 'retail', 'credit_limit' => ''])
            ->assertSessionHasNoErrors();

        $this->assertEquals(0, $customer->fresh()->credit_limit);
    }

    public function test_leaving_the_credit_limit_out_keeps_the_stored_limit(): void
    {
        $customer = $this->customer();

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('customers.update', $customer), ['name' => 'Rami R.', 'customer_group' => 'retail'])
            ->assertSessionHasNoErrors();

        $this->assertEquals(250, $customer->fresh()->credit_limit);
    }
}
