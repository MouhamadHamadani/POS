<?php

namespace Tests\Feature\Suppliers;

use App\Models\AuditLog;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Same bug as the customer form: the Delete <form> was nested inside the edit
 * <form>, the browser dropped it, and `_method=DELETE` ended up in the edit
 * form after `PUT`, so "Save Changes" sent every supplier to destroy().
 * See CustomerUpdateTest.
 */
class SupplierUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function supplier(): Supplier
    {
        return Supplier::create(['name' => 'Old Name', 'payment_terms' => 'NET30', 'is_active' => true]);
    }

    public function test_saving_an_edit_updates_the_supplier_and_does_not_delete_it(): void
    {
        $supplier = $this->supplier();

        $this->actingAs($this->admin())
            ->put(route('suppliers.update', $supplier), [
                'name' => 'New Name', 'payment_terms' => 'COD', 'is_active' => '1',
            ])
            ->assertRedirect(route('suppliers.show', $supplier));

        $this->assertNotSoftDeleted($supplier);
        $this->assertSame('New Name', $supplier->fresh()->name);
        $this->assertSame('COD', $supplier->fresh()->payment_terms);
        $this->assertTrue(AuditLog::where(['action' => 'update', 'model_type' => Supplier::class, 'model_id' => $supplier->id])->exists());
        $this->assertFalse(AuditLog::where(['action' => 'delete', 'model_type' => Supplier::class])->exists());
    }

    public function test_the_edit_form_sends_one_method_and_delete_is_a_separate_form(): void
    {
        $supplier = $this->supplier();

        $html = $this->actingAs($this->admin())->get(route('suppliers.edit', $supplier))->assertOk()->getContent();

        $this->assertSame(1, preg_match(
            '~<form[^>]*action="'.preg_quote(route('suppliers.update', $supplier), '~').'"[^>]*>(.*?)</form>~s',
            $html,
            $edit,
        ));
        $this->assertStringNotContainsString('<form', $edit[1]);
        $this->assertSame(1, substr_count($edit[1], 'name="_method"'));
        $this->assertStringContainsString('name="_method" value="PUT"', $edit[1]);
        $this->assertStringNotContainsString('value="DELETE"', $edit[1]);

        $this->assertStringContainsString('form="delete-supplier-form"', $edit[1]);
        $this->assertSame(1, preg_match(
            '~<form id="delete-supplier-form"[^>]*action="'.preg_quote(route('suppliers.destroy', $supplier), '~').'"[^>]*>(.*?)</form>~s',
            $html,
            $delete,
        ));
        $this->assertStringContainsString('name="_method" value="DELETE"', $delete[1]);
    }

    public function test_the_create_form_has_no_delete_form(): void
    {
        $this->actingAs($this->admin())->get(route('suppliers.create'))
            ->assertOk()
            ->assertDontSee('delete-supplier-form', false);
    }

    public function test_the_delete_form_target_still_soft_deletes_and_logs_it(): void
    {
        $supplier = $this->supplier();

        $this->actingAs($this->admin())->delete(route('suppliers.destroy', $supplier))
            ->assertRedirect(route('suppliers.index'));

        $this->assertSoftDeleted($supplier);
        $this->assertTrue(AuditLog::where(['action' => 'delete', 'model_type' => Supplier::class, 'model_id' => $supplier->id])->exists());
    }
}
