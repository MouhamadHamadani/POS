<?php

namespace Tests\Feature\Customers;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Save Changes" on the customer edit page used to delete the customer.
 *
 * The Delete <form> was nested inside the edit <form>. Browsers drop a nested
 * <form> tag, so its hidden `_method=DELETE` became a second `_method` field of
 * the edit form (PUT first, DELETE last) and Laravel routed every save to
 * destroy(). The confirm() on the inner form never ran either. Delete now lives
 * in its own form and the button points at it with the `form` attribute.
 */
class CustomerUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function customer(): Customer
    {
        return Customer::create([
            'name' => 'Old Name', 'customer_group' => 'retail', 'is_active' => true, 'credit_limit' => 0,
        ]);
    }

    public function test_saving_an_edit_updates_the_customer_and_does_not_delete_it(): void
    {
        $customer = $this->customer();

        $this->actingAs($this->admin())
            ->put(route('customers.update', $customer), [
                'name' => 'New Name', 'customer_group' => 'wholesale', 'is_active' => '1',
            ])
            ->assertRedirect(route('customers.show', $customer));

        $this->assertNotSoftDeleted($customer);
        $this->assertSame('New Name', $customer->fresh()->name);
        $this->assertSame('wholesale', $customer->fresh()->customer_group);
        $this->assertTrue(AuditLog::where(['action' => 'update', 'model_type' => Customer::class, 'model_id' => $customer->id])->exists());
        $this->assertFalse(AuditLog::where(['action' => 'delete', 'model_type' => Customer::class])->exists());
    }

    public function test_the_edit_form_sends_one_method_and_delete_is_a_separate_form(): void
    {
        $customer = $this->customer();

        $html = $this->actingAs($this->admin())->get(route('customers.edit', $customer))->assertOk()->getContent();

        // Everything from the edit form's opening tag to the first </form>. With
        // the old nested markup this span also held the delete form's tag and
        // its `_method=DELETE` input.
        $this->assertSame(1, preg_match(
            '~<form[^>]*action="'.preg_quote(route('customers.update', $customer), '~').'"[^>]*>(.*?)</form>~s',
            $html,
            $edit,
        ));
        $this->assertStringNotContainsString('<form', $edit[1]);
        $this->assertSame(1, substr_count($edit[1], 'name="_method"'));
        $this->assertStringContainsString('name="_method" value="PUT"', $edit[1]);
        $this->assertStringNotContainsString('value="DELETE"', $edit[1]);

        // The Delete button lives in the edit form but submits the separate one.
        $this->assertStringContainsString('form="delete-customer-form"', $edit[1]);
        $this->assertSame(1, preg_match(
            '~<form id="delete-customer-form"[^>]*action="'.preg_quote(route('customers.destroy', $customer), '~').'"[^>]*>(.*?)</form>~s',
            $html,
            $delete,
        ));
        $this->assertStringContainsString('name="_method" value="DELETE"', $delete[1]);
    }

    public function test_the_create_form_has_no_delete_form(): void
    {
        $this->actingAs($this->admin())->get(route('customers.create'))
            ->assertOk()
            ->assertDontSee('delete-customer-form', false);
    }

    public function test_the_delete_form_target_still_soft_deletes_and_logs_it(): void
    {
        $customer = $this->customer();

        $this->actingAs($this->admin())->delete(route('customers.destroy', $customer))
            ->assertRedirect(route('customers.index'));

        $this->assertSoftDeleted($customer);
        $this->assertTrue(AuditLog::where(['action' => 'delete', 'model_type' => Customer::class, 'model_id' => $customer->id])->exists());
    }
}
