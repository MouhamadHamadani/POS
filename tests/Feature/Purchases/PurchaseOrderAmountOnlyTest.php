<?php

namespace Tests\Feature\Purchases;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A PO with no lines, only the amount owed; confirming it is the moment the debt is recorded. */
class PurchaseOrderAmountOnlyTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->manager()->create();
        $this->supplier = Supplier::create(['name' => 'Acme Foods', 'payment_terms' => 'NET30', 'is_active' => true]);
    }

    private function storeAmountOnly(array $extra = [])
    {
        return $this->actingAs($this->manager)->post(route('purchases.store'), $extra + [
            'supplier_id' => $this->supplier->id,
            'status' => 'draft',
            'type' => 'amount_only',
            'expected_amount_usd' => 250.5,
        ]);
    }

    private function storeItemized(): PurchaseOrder
    {
        $product = Product::factory()->create(['tax_id' => null]);
        $this->actingAs($this->manager)->post(route('purchases.store'), [
            'supplier_id' => $this->supplier->id,
            'status' => 'draft',
            'items' => [['product_id' => $product->id, 'qty_ordered' => 2, 'cost_usd' => 10]],
        ])->assertSessionHasNoErrors();

        return PurchaseOrder::latest('id')->firstOrFail();
    }

    private function update(PurchaseOrder $po, array $data)
    {
        return $this->actingAs($this->manager)->put(route('purchases.update', $po), $data + [
            'supplier_id' => $this->supplier->id, 'status' => $po->status,
        ]);
    }

    public function test_an_amount_only_po_needs_no_items_and_stores_the_amount_as_its_total(): void
    {
        $this->storeAmountOnly()->assertSessionHasNoErrors()->assertRedirect();

        $po = PurchaseOrder::sole();
        $this->assertTrue($po->isAmountOnly());
        $this->assertSame(250.5, (float) $po->subtotal_usd);
        $this->assertSame(250.5, (float) $po->total_usd);
        $this->assertSame(0.0, (float) $po->tax_amount_usd);
        $this->assertSame(0.0, (float) $po->shipping_usd);
        $this->assertSame(0, $po->items()->count());
        $this->assertSame('amount_only', AuditLog::where('action', 'create')->where('model_type', PurchaseOrder::class)->sole()->new_values['type']);
    }

    public function test_shipping_and_items_posted_with_an_amount_only_po_are_ignored(): void
    {
        $product = Product::factory()->create();

        $this->storeAmountOnly([
            'shipping_usd' => 20,
            'items' => [['product_id' => $product->id, 'qty_ordered' => 5, 'cost_usd' => 3]],
        ])->assertSessionHasNoErrors();

        $po = PurchaseOrder::sole();
        $this->assertSame(250.5, (float) $po->total_usd);
        $this->assertSame(0.0, (float) $po->shipping_usd);
        $this->assertSame(0, $po->items()->count());
    }

    public function test_a_missing_or_zero_amount_is_refused(): void
    {
        $this->storeAmountOnly(['expected_amount_usd' => null])->assertSessionHasErrors('expected_amount_usd');
        $this->storeAmountOnly(['expected_amount_usd' => 0])->assertSessionHasErrors('expected_amount_usd');

        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_a_po_without_a_type_is_still_itemized_and_needs_items(): void
    {
        $this->actingAs($this->manager)->post(route('purchases.store'), [
            'supplier_id' => $this->supplier->id, 'status' => 'draft',
        ])->assertSessionHasErrors('items');

        $this->assertSame(PurchaseOrder::TYPE_ITEMIZED, $this->storeItemized()->type);
    }

    public function test_editing_changes_the_amount(): void
    {
        $this->storeAmountOnly();
        $po = PurchaseOrder::sole();

        $this->update($po, ['type' => 'amount_only', 'expected_amount_usd' => 99.99])->assertSessionHasNoErrors();

        $this->assertSame(99.99, (float) $po->fresh()->total_usd);
        $this->assertSame(99.99, (float) $po->fresh()->subtotal_usd);
    }

    public function test_switching_to_itemized_requires_items_then_saves_them(): void
    {
        $this->storeAmountOnly();
        $po = PurchaseOrder::sole();
        $product = Product::factory()->create(['tax_id' => null]);

        $this->update($po, ['type' => 'itemized'])->assertSessionHasErrors('items');
        $this->assertTrue($po->fresh()->isAmountOnly());

        $this->update($po, [
            'type' => 'itemized',
            'items' => [['product_id' => $product->id, 'qty_ordered' => 3, 'cost_usd' => 4]],
        ])->assertSessionHasNoErrors();

        $po->refresh();
        $this->assertSame(PurchaseOrder::TYPE_ITEMIZED, $po->type);
        $this->assertSame(1, $po->items()->count());
        $this->assertSame(12.0, (float) $po->total_usd);
        $this->assertSame(1, AuditLog::where('action', 'po_type_change')->where('model_id', $po->id)->count());
    }

    public function test_switching_an_itemized_po_to_amount_only_deletes_its_lines(): void
    {
        $po = $this->storeItemized();
        $this->assertSame(1, $po->items()->count());

        $this->update($po, ['type' => 'amount_only', 'expected_amount_usd' => 75, 'shipping_usd' => 5])->assertSessionHasNoErrors();

        $po->refresh();
        $this->assertTrue($po->isAmountOnly());
        $this->assertSame(0, $po->items()->count());
        $this->assertSame(75.0, (float) $po->total_usd);
        $this->assertSame(0.0, (float) $po->shipping_usd);

        $log = AuditLog::where('action', 'po_type_change')->where('model_id', $po->id)->sole();
        $this->assertSame(['type' => 'itemized'], $log->old_values);
        $this->assertSame(['type' => 'amount_only'], $log->new_values);
    }

    public function test_the_type_cannot_change_once_goods_were_received(): void
    {
        $po = $this->storeItemized();
        $po->items()->update(['qty_received' => 1]);

        $this->update($po, ['type' => 'amount_only', 'expected_amount_usd' => 75])
            ->assertSessionHasErrors(['type' => __('messages.po_type_locked')]);

        $this->assertSame(PurchaseOrder::TYPE_ITEMIZED, $po->fresh()->type);
        $this->assertSame(1, $po->items()->count());
    }

    public function test_receive_is_refused_for_an_amount_only_po(): void
    {
        $this->storeAmountOnly();
        $po = PurchaseOrder::sole();

        $this->post(route('purchases.receive', $po), ['receipts' => []])
            ->assertSessionHasErrors(['status' => __('messages.po_amount_only_no_receive')]);

        $this->assertSame('draft', $po->fresh()->status);
        $this->assertSame(0.0, (float) $this->supplier->fresh()->balance);
    }

    public function test_confirm_adds_the_amount_to_the_supplier_balance_exactly_once(): void
    {
        $this->storeAmountOnly();
        $po = PurchaseOrder::sole();

        $this->post(route('purchases.confirm', $po))->assertSessionHasNoErrors()->assertRedirect(route('purchases.show', $po));

        $po->refresh();
        $this->assertSame(PurchaseOrder::STATUS_RECEIVED, $po->status);
        $this->assertNotNull($po->received_at);
        $this->assertSame(250.5, (float) $this->supplier->fresh()->balance);
        $log = AuditLog::where('action', 'po_confirm_amount')->where('model_id', $po->id)->sole();
        $this->assertSame(250.5, (float) $log->new_values['amount_usd']);

        $this->post(route('purchases.confirm', $po))->assertSessionHasErrors('status');
        $this->assertSame(250.5, (float) $this->supplier->fresh()->balance);
        $this->assertSame(1, AuditLog::where('action', 'po_confirm_amount')->count());
    }

    public function test_confirm_refuses_an_itemized_po(): void
    {
        $po = $this->storeItemized();

        $this->post(route('purchases.confirm', $po))->assertSessionHasErrors(['status' => __('messages.po_confirm_itemized')]);

        $this->assertSame('draft', $po->fresh()->status);
        $this->assertSame(0.0, (float) $this->supplier->fresh()->balance);
    }

    public function test_confirm_refuses_a_cancelled_po(): void
    {
        $this->storeAmountOnly();
        $po = PurchaseOrder::sole();
        $this->post(route('purchases.transition', $po), ['to' => 'cancelled'])->assertSessionHasNoErrors();

        $this->post(route('purchases.confirm', $po))->assertSessionHasErrors('status');

        $this->assertSame(PurchaseOrder::STATUS_CANCELLED, $po->fresh()->status);
        $this->assertSame(0.0, (float) $this->supplier->fresh()->balance);
    }

    public function test_a_confirmed_amount_counts_in_total_spent_and_a_supplier_payment_settles_it(): void
    {
        $this->storeAmountOnly();
        $po = PurchaseOrder::sole();
        $this->post(route('purchases.confirm', $po));

        $this->get(route('suppliers.show', $this->supplier))->assertOk()
            ->assertViewHas('totalSpent', 250.5)->assertSee($po->po_number);

        $this->post(route('suppliers.payment', $this->supplier), ['amount_usd' => 100, 'method' => 'cash_usd'])->assertSessionHasNoErrors();
        $this->assertSame(150.5, (float) $this->supplier->fresh()->balance);

        // A received amount-only PO can still be closed.
        $this->post(route('purchases.transition', $po), ['to' => 'closed'])->assertSessionHasNoErrors();
        $this->assertSame(PurchaseOrder::STATUS_CLOSED, $po->fresh()->status);
    }

    public function test_the_pages_render_an_amount_only_po_without_items(): void
    {
        $this->storeAmountOnly();
        $po = PurchaseOrder::sole();

        $this->get(route('purchases.index'))->assertOk()->assertSee(__('purchases.type_amount_only'));

        $this->get(route('purchases.show', $po))->assertOk()
            ->assertSee(__('purchases.confirm_amount'))
            ->assertDontSee(__('purchases.receive_goods'))
            ->assertDontSee(__('purchases.items'));

        $this->get(route('purchases.edit', $po))->assertOk()
            ->assertSee("type: 'amount_only'", false)
            ->assertSee('value="250.5"', false);

        $this->post(route('purchases.confirm', $po));
        $this->get(route('purchases.show', $po))->assertOk()->assertDontSee(__('purchases.confirm_amount'));
    }

    public function test_the_arabic_form_and_show_page_render(): void
    {
        $this->manager->update(['language' => 'ar']);
        $this->storeAmountOnly();
        $po = PurchaseOrder::sole();

        $this->get(route('purchases.create'))->assertOk()->assertSee('مبلغ فقط')->assertSee('+ منتج جديد');
        $this->get(route('purchases.show', $po))->assertOk()->assertSee('تأكيد وإضافة إلى رصيد المورّد');
    }
}
