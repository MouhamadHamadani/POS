<?php

namespace Tests\Feature\Expenses;

use App\Models\CashMovement;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\Tax;
use App\Models\User;
use App\Services\ShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An expense paid from the drawer is a cash movement on the open shift, and the
 * shift's expected cash — close screen, stored figures, summary — moves with it.
 */
class DrawerExpenseTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->manager()->create();
        Setting::set('exchange_rate', 90000, 'currency', 'float');
    }

    private function openShift(?User $user = null, float $usd = 100, float $lbp = 5_000_000): Shift
    {
        return Shift::create([
            'user_id' => ($user ?? $this->manager)->id, 'opened_at' => now(),
            'opening_cash_usd' => $usd, 'opening_cash_lbp' => $lbp, 'status' => Shift::STATUS_OPEN,
        ]);
    }

    private function cat(string $name): int
    {
        return (int) ExpenseCategory::where('name', $name)->value('id');
    }

    private function spend(array $over = [])
    {
        return $this->actingAs($this->manager)->post('/expenses', array_merge([
            'expense_category_id' => $this->cat('Rent'), 'date' => now()->format('Y-m-d'),
            'currency' => 'usd', 'amount' => 12.5, 'paid_from' => 'drawer', 'notes' => 'March rent',
        ], $over));
    }

    private function totals(Shift $shift): array
    {
        return app(ShiftService::class)->totals($shift->fresh());
    }

    public function test_a_drawer_expense_creates_the_linked_cash_movement_and_lowers_expected_cash(): void
    {
        $shift = $this->openShift();

        $this->spend()->assertRedirect('/expenses')->assertSessionHasNoErrors();

        $expense = Expense::sole();
        $movement = CashMovement::sole();
        $this->assertSame($movement->id, $expense->cash_movement_id);
        $this->assertSame($shift->id, $expense->shift_id);
        $this->assertSame($shift->id, $movement->shift_id);
        $this->assertSame(CashMovement::TYPE_OUT, $movement->type);
        $this->assertSame('usd', $movement->currency);
        $this->assertEqualsWithDelta(12.5, (float) $movement->amount_usd, 0.0001);
        $this->assertSame('Expense: Rent — March rent', $movement->note);
        $this->assertSame($this->manager->id, $movement->created_by);

        $t = $this->totals($shift);
        $this->assertEqualsWithDelta(12.5, $t['cash_out_usd'], 0.0001);
        $this->assertEqualsWithDelta(87.5, $t['expected_usd'], 0.0001);        // 100 - 12.50
        $this->assertEqualsWithDelta(5_000_000, $t['expected_lbp'], 0.5);       // dollars left the drawer, not liras
    }

    public function test_an_lbp_drawer_expense_comes_out_of_the_lbp_cash(): void
    {
        $shift = $this->openShift();

        $this->spend(['expense_category_id' => $this->cat('Generator'), 'currency' => 'lbp', 'amount' => 450_000, 'notes' => ''])
            ->assertSessionHasNoErrors();

        $this->assertSame('Expense: Generator', CashMovement::sole()->note);   // no dangling dash without notes

        $t = $this->totals($shift);
        $this->assertEqualsWithDelta(100, $t['expected_usd'], 0.0001);
        $this->assertEqualsWithDelta(4_550_000, $t['expected_lbp'], 0.5);       // 5,000,000 - 450,000
    }

    public function test_closing_stores_the_reduced_expected_cash_and_the_variance_follows(): void
    {
        $shift = $this->openShift();
        $this->spend()->assertSessionHasNoErrors();
        $this->spend(['expense_category_id' => $this->cat('Water'), 'currency' => 'lbp', 'amount' => 450_000, 'notes' => 'bottles'])->assertSessionHasNoErrors();

        // The close screen shows the new expected cash and the expenses taken from the drawer.
        $this->actingAs($this->manager)->get('/shifts/close')->assertOk()
            ->assertSee('87.50')
            ->assertSee('4,550,000')
            ->assertSee('Expenses paid from this drawer')
            ->assertSee('Rent')->assertSee('Water');

        $this->actingAs($this->manager)->post('/shifts/close', [
            'closing_cash_usd' => 87.00, 'closing_cash_lbp' => 4_550_000,
        ])->assertRedirect('/shifts/open');

        $shift->refresh();
        $this->assertEqualsWithDelta(87.5, (float) $shift->expected_cash_usd, 0.0001);
        $this->assertEqualsWithDelta(4_550_000, (float) $shift->expected_cash_lbp, 0.5);
        $this->assertEqualsWithDelta(-0.5, (float) $shift->variance_usd, 0.0001);
        $this->assertEqualsWithDelta(0, (float) $shift->variance_lbp, 0.5);
    }

    public function test_sales_and_expenses_and_cash_in_all_land_in_one_expected_figure(): void
    {
        $shift = $this->openShift(usd: 100, lbp: 0);
        $vat = Tax::create(['name' => 'Lebanese VAT', 'rate' => 0.11, 'is_inclusive' => false, 'is_default' => true, 'is_active' => true]);
        $product = Product::factory()->create(['price_usd' => 50, 'cost_usd' => 20, 'stock_qty' => 10, 'tax_id' => $vat->id, 'is_taxable' => true]);

        // 55.50 sale paid with 100.00 (44.50 change): the drawer gains 55.50.
        $this->actingAs($this->manager)->postJson('/pos/api/sales', [
            'cart' => [['product_id' => $product->id, 'qty' => 1]],
            'payment' => ['method' => 'cash_usd', 'amount_usd' => 100.00],
        ])->assertCreated();
        $this->spend(['amount' => 10])->assertSessionHasNoErrors();                  // -10
        CashMovement::create([                                                         // +20 cash in
            'shift_id' => $shift->id, 'type' => CashMovement::TYPE_IN, 'currency' => 'usd',
            'amount_usd' => 20, 'amount_lbp' => 1_800_000, 'note' => 'float top-up', 'created_by' => $this->manager->id,
        ]);

        $t = $this->totals($shift);
        $this->assertEqualsWithDelta(165.50, $t['expected_usd'], 0.0001);             // 100 + 55.50 + 20 - 10
        $this->assertEqualsWithDelta(0, $t['expected_lbp'], 0.5);                     // an in-movement in USD leaves the LBP drawer alone

        $this->actingAs($this->manager)->post('/shifts/close', ['closing_cash_usd' => 165.50, 'closing_cash_lbp' => 0]);
        $this->assertEqualsWithDelta(165.50, (float) $shift->fresh()->expected_cash_usd, 0.0001);
        $this->assertEqualsWithDelta(0, (float) $shift->fresh()->variance_usd, 0.0001);
    }

    public function test_a_drawer_expense_is_rejected_with_no_open_shift(): void
    {
        $this->spend()->assertSessionHasErrors('paid_from');

        $this->assertSame(0, Expense::count());
        $this->assertSame(0, CashMovement::count());

        // Someone else's open shift does not count: it has to be the paying user's own.
        $this->openShift(User::factory()->create());
        $this->spend()->assertSessionHasErrors('paid_from');
        $this->assertSame(0, Expense::count());

        // and an outside expense needs no shift at all
        $this->spend(['paid_from' => 'outside'])->assertSessionHasNoErrors();
        $this->assertSame(1, Expense::count());
        $this->assertSame(0, CashMovement::count());
    }

    public function test_the_form_only_offers_the_drawer_when_a_shift_is_open(): void
    {
        $this->actingAs($this->manager)->get('/expenses/create')->assertOk()
            ->assertSee('Paying from the drawer needs an open shift');

        $this->openShift();
        $this->actingAs($this->manager)->get('/expenses/create')->assertOk()
            ->assertDontSee('Paying from the drawer needs an open shift');
    }

    public function test_editing_before_close_keeps_one_movement_in_step(): void
    {
        $shift = $this->openShift();
        $this->spend()->assertSessionHasNoErrors();
        $expense = Expense::sole();

        $this->actingAs($this->manager)->put("/expenses/{$expense->id}", [
            'expense_category_id' => $this->cat('Rent'), 'date' => now()->format('Y-m-d'),
            'currency' => 'usd', 'amount' => 30, 'paid_from' => 'drawer', 'notes' => 'corrected',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, CashMovement::count());
        $this->assertEqualsWithDelta(30, (float) CashMovement::sole()->amount_usd, 0.0001);
        $this->assertSame('Expense: Rent — corrected', CashMovement::sole()->note);
        $this->assertSame(CashMovement::sole()->id, $expense->fresh()->cash_movement_id);
        $this->assertEqualsWithDelta(70, $this->totals($shift)['expected_usd'], 0.0001);

        // moved out of the drawer: the movement goes, the cash comes back
        $this->actingAs($this->manager)->put("/expenses/{$expense->id}", [
            'expense_category_id' => $this->cat('Rent'), 'date' => now()->format('Y-m-d'),
            'currency' => 'usd', 'amount' => 30, 'paid_from' => 'outside',
        ])->assertSessionHasNoErrors();
        $this->assertSame(0, CashMovement::count());
        $this->assertNull($expense->fresh()->shift_id);
        $this->assertNull($expense->fresh()->cash_movement_id);
        $this->assertEqualsWithDelta(100, $this->totals($shift)['expected_usd'], 0.0001);

        // and back into it
        $this->actingAs($this->manager)->put("/expenses/{$expense->id}", [
            'expense_category_id' => $this->cat('Rent'), 'date' => now()->format('Y-m-d'),
            'currency' => 'usd', 'amount' => 5, 'paid_from' => 'drawer',
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, CashMovement::count());
        $this->assertEqualsWithDelta(95, $this->totals($shift)['expected_usd'], 0.0001);
    }

    public function test_deleting_before_close_removes_the_cash_movement_too(): void
    {
        $shift = $this->openShift();
        $this->spend()->assertSessionHasNoErrors();
        $expense = Expense::sole();

        $this->actingAs($this->manager)->delete("/expenses/{$expense->id}")->assertRedirect('/expenses');

        $this->assertSoftDeleted('expenses', ['id' => $expense->id]);
        $this->assertSame(0, CashMovement::count());
        $this->assertEqualsWithDelta(100, $this->totals($shift)['expected_usd'], 0.0001);
        $this->actingAs($this->manager)->get('/shifts/close')->assertOk()->assertDontSee('Expenses paid from this drawer');
    }

    public function test_a_drawer_expense_is_frozen_once_its_shift_closes(): void
    {
        $shift = $this->openShift();
        $this->spend()->assertSessionHasNoErrors();
        $expense = Expense::sole();

        $this->actingAs($this->manager)->post('/shifts/close', ['closing_cash_usd' => 87.5, 'closing_cash_lbp' => 5_000_000]);
        $this->assertSame(Shift::STATUS_CLOSED, $shift->fresh()->status);

        $message = 'paid from the drawer of a shift that is already closed';

        $this->actingAs($this->manager)->put("/expenses/{$expense->id}", [
            'expense_category_id' => $this->cat('Rent'), 'date' => now()->format('Y-m-d'),
            'currency' => 'usd', 'amount' => 1, 'paid_from' => 'outside',
        ])->assertSessionHasErrors('expense');
        $this->actingAs($this->manager)->delete("/expenses/{$expense->id}")->assertSessionHasErrors('expense');
        $this->actingAs($this->manager)->get("/expenses/{$expense->id}/edit")->assertRedirect('/expenses');
        $this->actingAs($this->manager)->get('/expenses')->assertOk()->assertSee('Locked')->assertSee($message, false);

        $expense->refresh();
        $this->assertNull($expense->deleted_at);
        $this->assertEqualsWithDelta(12.5, (float) $expense->amount_usd, 0.0001);
        $this->assertSame('drawer', $expense->paid_from);
        $this->assertSame(1, CashMovement::count());
        $this->assertEqualsWithDelta(87.5, (float) $shift->fresh()->expected_cash_usd, 0.0001); // the closed figures never move
    }

    public function test_an_outside_expense_is_never_frozen_by_a_shift_closing(): void
    {
        $shift = $this->openShift();
        $this->spend(['paid_from' => 'outside'])->assertSessionHasNoErrors();
        $expense = Expense::sole();
        $this->actingAs($this->manager)->post('/shifts/close', ['closing_cash_usd' => 100, 'closing_cash_lbp' => 5_000_000]);

        $this->actingAs($this->manager)->put("/expenses/{$expense->id}", [
            'expense_category_id' => $this->cat('Rent'), 'date' => now()->format('Y-m-d'),
            'currency' => 'usd', 'amount' => 60, 'paid_from' => 'outside',
        ])->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(60, (float) $expense->fresh()->amount_usd, 0.0001);

        $this->actingAs($this->manager)->delete("/expenses/{$expense->id}")->assertSessionHasNoErrors();
        $this->assertSoftDeleted('expenses', ['id' => $expense->id]);
    }

    public function test_an_expense_cannot_be_moved_into_the_drawer_without_an_open_shift(): void
    {
        $this->spend(['paid_from' => 'outside'])->assertSessionHasNoErrors();
        $expense = Expense::sole();

        $this->actingAs($this->manager)->put("/expenses/{$expense->id}", [
            'expense_category_id' => $this->cat('Rent'), 'date' => now()->format('Y-m-d'),
            'currency' => 'usd', 'amount' => 12.5, 'paid_from' => 'drawer',
        ])->assertSessionHasErrors('paid_from');

        $this->assertSame('outside', $expense->fresh()->paid_from);
        $this->assertSame(0, CashMovement::count());
    }
}
