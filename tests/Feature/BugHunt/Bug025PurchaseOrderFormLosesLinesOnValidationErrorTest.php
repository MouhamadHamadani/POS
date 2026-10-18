<?php

namespace Tests\Feature\BugHunt;

use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BUG-025: a purchase order that fails validation comes back with every line item gone.
 *
 * The line inputs (qty / cost / tax) carry no `required`, so the browser lets a cleared cost
 * through; the server rejects it (`items.*.cost_usd` is required) and redirects back with the old
 * input flashed. But purchases/form.blade.php builds its lines only from `$items` (empty on create,
 * the stored lines on edit) and never from old('items'), so the user's whole typed order disappears
 * (or, on edit, silently reverts to the stored lines) and has to be entered again.
 */
class Bug025PurchaseOrderFormLosesLinesOnValidationErrorTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_create_form_keeps_the_typed_lines_after_a_validation_error(): void
    {
        $supplier = Supplier::create(['name' => 'Acme Foods', 'payment_terms' => 'NET30', 'is_active' => true]);
        $first = Product::factory()->create(['name' => 'Zzfirstproduct']);
        $second = Product::factory()->create(['name' => 'Zzsecondproduct']);
        $manager = User::factory()->manager()->create();

        $page = $this->actingAs($manager)->from(route('purchases.create'))->followingRedirects()
            ->post(route('purchases.store'), [
                'supplier_id' => $supplier->id,
                'items' => [
                    ['product_id' => $first->id, 'product_name' => $first->name, 'qty_ordered' => 5, 'cost_usd' => 2],
                    // the user cleared this line's cost; the form has no `required` to stop it
                    ['product_id' => $second->id, 'product_name' => $second->name, 'qty_ordered' => 3, 'cost_usd' => ''],
                ],
            ]);

        $page->assertSee('items.1.cost_usd', false); // we are looking at the form, with the validation error
        $html = $page->getContent();
        $this->assertMatchesRegularExpression(
            '/lines:\s*\[\s*\{/',
            $html,
            'The form came back with no line items after a validation error: the typed lines were discarded.',
        );
    }
}
