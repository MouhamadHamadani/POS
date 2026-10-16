<?php

namespace Tests\Feature\BugHunt;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\BugHunt\Concerns\SubmitsRenderedForms;
use Tests\TestCase;

/**
 * BUG-008: emptying "Credit limit" on the customer form ends in a server error.
 *
 * The field is not marked required (no asterisk, validated `nullable`). A blank number
 * input is posted as "", Laravel turns it into null, and the controller writes
 * `credit_limit = NULL` into a NOT NULL DEFAULT 0 column. Create and edit both hit it.
 * Products already guard this exact case (StoreProductRequest drops blank numerics);
 * customers do not.
 */
class Bug008CustomerBlankCreditLimitCrashesTest extends TestCase
{
    use RefreshDatabase;
    use SubmitsRenderedForms;

    public function test_saving_the_edit_form_with_a_blank_credit_limit_does_not_crash(): void
    {
        $customer = Customer::create(['name' => 'Rami', 'customer_group' => 'retail', 'is_active' => true, 'credit_limit' => 250]);
        $admin = User::factory()->admin()->create();

        $html = $this->actingAs($admin)->get(route('customers.edit', $customer))->assertOk()->getContent();

        $response = $this->submitRenderedForm($html, route('customers.update', $customer), set: ['credit_limit' => '']);

        $this->assertLessThan(500, $response->getStatusCode(), 'A blank credit limit produced a server error: '.($response->exception?->getMessage() ?? ''));
        $this->assertNotNull($customer->fresh()->credit_limit);
    }

    public function test_creating_a_customer_with_a_blank_credit_limit_does_not_crash(): void
    {
        $admin = User::factory()->admin()->create();

        $html = $this->actingAs($admin)->get(route('customers.create'))->assertOk()->getContent();

        $response = $this->submitRenderedForm($html, route('customers.store'), set: ['name' => 'Walk-in Wael', 'credit_limit' => '']);

        $this->assertLessThan(500, $response->getStatusCode(), 'A blank credit limit produced a server error: '.($response->exception?->getMessage() ?? ''));
    }
}
