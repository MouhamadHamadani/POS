<?php

namespace Tests\Feature\Purchases;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** BUG-037: a PO keeps its deactivated supplier selectable on edit; new POs only offer active suppliers. */
class PurchaseOrderInactiveSupplierTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<int,string> the option labels of the supplier select */
    private function supplierOptions(string $html): array
    {
        $this->assertSame(1, preg_match('~<select name="supplier_id".*?</select>~s', $html, $select));
        preg_match_all('~<option value="(\d*)"[^>]*>(.*?)</option>~s', $select[0], $m, PREG_SET_ORDER);

        return array_map(fn ($o) => preg_replace('/\s+/', ' ', trim($o[2])), $m);
    }

    public function test_edit_offers_the_pos_inactive_supplier_marked_inactive_and_no_other_inactive_one(): void
    {
        $manager = User::factory()->manager()->create();
        $own = Supplier::create(['name' => 'Acme Foods', 'payment_terms' => 'NET30', 'is_active' => true]);
        Supplier::create(['name' => 'Beta Trading', 'payment_terms' => 'NET30', 'is_active' => true]);
        Supplier::create(['name' => 'Retired Co', 'payment_terms' => 'NET30', 'is_active' => false]);
        $this->actingAs($manager)->post(route('purchases.store'), [
            'supplier_id' => $own->id, 'status' => 'draft',
            'items' => [['product_id' => Product::factory()->create()->id, 'qty_ordered' => 1, 'cost_usd' => 5]],
        ])->assertSessionHasNoErrors();
        $own->update(['is_active' => false]);

        $options = $this->supplierOptions($this->actingAs($manager)->get(route('purchases.edit', PurchaseOrder::firstOrFail()))->assertOk()->getContent());

        $this->assertContains('Acme Foods (Inactive)', $options);
        $this->assertContains('Beta Trading', $options);
        $this->assertNotContains('Retired Co', $options);
    }

    public function test_the_create_form_offers_active_suppliers_only(): void
    {
        Supplier::create(['name' => 'Acme Foods', 'payment_terms' => 'NET30', 'is_active' => true]);
        Supplier::create(['name' => 'Retired Co', 'payment_terms' => 'NET30', 'is_active' => false]);

        $options = $this->supplierOptions($this->actingAs(User::factory()->manager()->create())->get(route('purchases.create'))->assertOk()->getContent());

        $this->assertContains('Acme Foods', $options);
        $this->assertNotContains('Retired Co', $options);
        $this->assertNotContains('Retired Co (Inactive)', $options);
    }
}
