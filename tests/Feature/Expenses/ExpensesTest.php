<?php

namespace Tests\Feature\Expenses;

use App\Models\AuditLog;
use App\Models\CashMovement;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\User;
use App\Services\ExpenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The expenses module: defaults, recording, editing, the list, last month's copy, and who may touch it. */
class ExpensesTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->manager()->create();
    }

    private function cat(string $name): int
    {
        return (int) ExpenseCategory::where('name', $name)->value('id');
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'expense_category_id' => $this->cat('Rent'),
            'date' => now()->format('Y-m-d'),
            'currency' => 'usd',
            'amount' => 100,
            'paid_from' => 'outside',
        ], $over);
    }

    private function book(string $category, float $amount, string $date, string $currency = 'usd', string $paidFrom = 'outside'): Expense
    {
        return app(ExpenseService::class)->create($this->payload([
            'expense_category_id' => $this->cat($category), 'amount' => $amount, 'date' => $date,
            'currency' => $currency, 'paid_from' => $paidFrom,
        ]), $this->manager);
    }

    public function test_the_bilingual_default_categories_are_there(): void
    {
        $this->assertSame(11, ExpenseCategory::count());
        $this->assertSame(['Rent', 'Salaries', 'Subscriptions', 'Insurance'], ExpenseCategory::where('kind', 'fixed')->orderBy('sort_order')->pluck('name')->all());
        $this->assertSame(
            ['Electricity', 'Generator', 'Water', 'Fuel', 'Internet & phone', 'Maintenance', 'Other'],
            ExpenseCategory::where('kind', 'running')->orderBy('sort_order')->pluck('name')->all()
        );
        $this->assertSame('إيجار', ExpenseCategory::where('name', 'Rent')->value('name_ar'));
        $this->assertSame('إنترنت وهاتف', ExpenseCategory::where('name', 'Internet & phone')->value('name_ar'));
        $this->assertSame(11, ExpenseCategory::where('is_active', true)->count());
    }

    public function test_a_usd_expense_outside_the_drawer_stores_both_currencies_and_touches_no_shift(): void
    {
        Setting::set('exchange_rate', 90000, 'currency', 'float');

        $this->actingAs($this->manager)->post('/expenses', $this->payload([
            'amount' => 12.5, 'payee' => 'Landlord', 'reference' => 'INV-77', 'notes' => 'October rent',
        ]))->assertRedirect('/expenses')->assertSessionHasNoErrors();

        $e = Expense::sole();
        $this->assertEqualsWithDelta(12.5, (float) $e->amount_usd, 0.0001);
        $this->assertEqualsWithDelta(1_125_000, (float) $e->amount_lbp, 0.5);
        $this->assertSame('usd', $e->currency);
        $this->assertSame('outside', $e->paid_from);
        $this->assertNull($e->shift_id);
        $this->assertNull($e->cash_movement_id);
        $this->assertSame($this->manager->id, $e->created_by);
        $this->assertSame(now()->format('Y-m-d'), $e->getRawOriginal('date'));
        $this->assertSame(0, CashMovement::count());

        $log = AuditLog::where('model_type', Expense::class)->where('action', 'create')->sole();
        $this->assertSame($e->id, $log->model_id);
        $this->assertSame('INV-77', $log->new_values['reference']);
    }

    public function test_an_lbp_expense_keeps_the_lbp_figure_as_typed_and_converts_to_usd(): void
    {
        Setting::set('exchange_rate', 90000, 'currency', 'float');

        $this->actingAs($this->manager)->post('/expenses', $this->payload([
            'expense_category_id' => $this->cat('Generator'), 'currency' => 'lbp', 'amount' => 1_800_000,
        ]))->assertSessionHasNoErrors();

        $e = Expense::sole();
        $this->assertSame('lbp', $e->currency);
        $this->assertEqualsWithDelta(1_800_000, (float) $e->amount_lbp, 0.5);
        $this->assertEqualsWithDelta(20.00, (float) $e->amount_usd, 0.0001);
        $this->assertEqualsWithDelta(1_800_000, $e->enteredAmount(), 0.5);
    }

    public function test_validation(): void
    {
        $this->actingAs($this->manager);

        $this->post('/expenses', $this->payload(['amount' => 0]))->assertSessionHasErrors('amount');
        $this->post('/expenses', $this->payload(['amount' => -5]))->assertSessionHasErrors('amount');
        $this->post('/expenses', $this->payload(['currency' => 'eur']))->assertSessionHasErrors('currency');
        $this->post('/expenses', $this->payload(['paid_from' => 'moon']))->assertSessionHasErrors('paid_from');
        $this->post('/expenses', $this->payload(['expense_category_id' => 9999]))->assertSessionHasErrors('expense_category_id');
        $this->post('/expenses', $this->payload(['date' => 'nope']))->assertSessionHasErrors('date');

        ExpenseCategory::whereKey($this->cat('Rent'))->update(['is_active' => false]);
        $this->post('/expenses', $this->payload())->assertSessionHasErrors(['expense_category_id' => 'Pick an active expense category.']);

        $this->assertSame(0, Expense::count());
    }

    public function test_an_outside_expense_can_be_edited_and_deleted_whenever(): void
    {
        $e = $this->book('Rent', 100, now()->format('Y-m-d'));

        $this->actingAs($this->manager)->put("/expenses/{$e->id}", $this->payload([
            'expense_category_id' => $this->cat('Insurance'), 'amount' => 75, 'notes' => 'changed',
        ]))->assertRedirect('/expenses')->assertSessionHasNoErrors();

        $e->refresh();
        $this->assertSame($this->cat('Insurance'), $e->expense_category_id);
        $this->assertEqualsWithDelta(75, (float) $e->amount_usd, 0.0001);
        $this->assertSame(1, AuditLog::where('model_type', Expense::class)->where('action', 'update')->count());

        $this->actingAs($this->manager)->delete("/expenses/{$e->id}")->assertRedirect('/expenses');
        $this->assertSoftDeleted('expenses', ['id' => $e->id]);
        $this->assertSame(1, AuditLog::where('model_type', Expense::class)->where('action', 'delete')->count());
    }

    public function test_an_inactive_category_stays_on_the_expenses_that_already_use_it(): void
    {
        $e = $this->book('Rent', 100, now()->format('Y-m-d'));
        ExpenseCategory::whereKey($this->cat('Rent'))->update(['is_active' => false]);

        // Editing the amount keeps the (now inactive) category; the form still lists it.
        $this->actingAs($this->manager)->get("/expenses/{$e->id}/edit")->assertOk()->assertSee('Rent');
        $this->actingAs($this->manager)->put("/expenses/{$e->id}", $this->payload(['amount' => 90]))->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(90, (float) $e->fresh()->amount_usd, 0.0001);

        // ...but it is no longer offered for a brand-new expense.
        $this->actingAs($this->manager)->get('/expenses/create')->assertOk()->assertDontSee('Rent');
    }

    public function test_the_list_filters_and_totals(): void
    {
        Setting::set('exchange_rate', 90000, 'currency', 'float');
        $this->book('Rent', 500, '2026-03-01');
        $this->book('Salaries', 900_000, '2026-03-05', 'lbp');                  // $10.00
        $this->book('Electricity', 40, '2026-03-10', 'usd', 'outside');
        $this->book('Fuel', 20, '2026-04-02');                                  // another month
        $deleted = $this->book('Water', 99, '2026-03-12');
        app(ExpenseService::class)->delete($deleted, $this->manager);

        $totals = app(ExpenseService::class)->totals(app(ExpenseService::class)->filters(['from' => '2026-03-01', 'to' => '2026-03-31']));
        $this->assertEqualsWithDelta(550.00, $totals['total'], 0.0001);
        $this->assertEqualsWithDelta(510.00, $totals['fixed'], 0.0001);
        $this->assertEqualsWithDelta(40.00, $totals['running'], 0.0001);
        $this->assertEqualsWithDelta(45_000_000 + 900_000 + 3_600_000, $totals['total_lbp'], 1);

        $this->actingAs($this->manager)->get('/expenses?from=2026-03-01&to=2026-03-31')
            ->assertOk()
            ->assertSee('2026-03-01')->assertSee('2026-03-05')->assertSee('2026-03-10')
            ->assertDontSee('2026-04-02')->assertDontSee('99.00')   // April's fuel and the deleted water bill
            ->assertSee('550.00')->assertSee('510.00')->assertSee('40.00');

        $this->actingAs($this->manager)->get('/expenses?from=2026-03-01&to=2026-03-31&kind=running')
            ->assertOk()->assertSee('2026-03-10')->assertDontSee('2026-03-05');
        $this->actingAs($this->manager)->get('/expenses?from=2026-03-01&to=2026-04-30&category_id='.$this->cat('Fuel'))
            ->assertOk()->assertSee('2026-04-02')->assertDontSee('2026-03-10');
    }

    public function test_the_list_defaults_to_the_current_month(): void
    {
        $this->book('Rent', 123, now()->startOfMonth()->format('Y-m-d'));
        $this->book('Fuel', 456, now()->subMonths(2)->format('Y-m-d'));

        $this->actingAs($this->manager)->get('/expenses')->assertOk()->assertSee('123.00')->assertDontSee('456.00');
    }

    public function test_csv_export_follows_the_filters_and_is_blocked_in_a_demo_build(): void
    {
        $this->book('Rent', 100, '2026-03-01');
        $this->book('Fuel', 20, '2026-04-02');

        $rows = app(ExpenseService::class)->exportRows(app(ExpenseService::class)->filters(['from' => '2026-03-01', 'to' => '2026-03-31']));
        $this->assertCount(2, $rows); // heading + the March row
        $this->assertSame('Rent', $rows[1][1]);
        $this->assertSame(100.0, $rows[1][5]);

        $this->actingAs($this->manager)->get('/expenses/export?from=2026-03-01&to=2026-03-31')->assertOk()->assertHeader('content-disposition');

        config(['pos.demo_mode' => true]);
        $this->actingAs($this->manager)->get('/expenses/export')->assertSessionHasErrors('demo');
        $this->actingAs($this->manager)->get('/expenses')->assertOk();   // the module itself works in a demo
    }

    public function test_duplicating_last_months_fixed_expenses_makes_unsaved_drafts_for_review(): void
    {
        $last = now()->subMonthNoOverflow()->startOfMonth();
        $this->book('Rent', 800, $last->copy()->addDays(2)->format('Y-m-d'));
        $this->book('Salaries', 1_800_000, $last->copy()->addDays(26)->format('Y-m-d'), 'lbp');
        $this->book('Electricity', 55, $last->copy()->addDays(10)->format('Y-m-d'));        // running: not copied
        $this->book('Insurance', 40, $last->copy()->subDay()->format('Y-m-d'));             // two months back: not copied
        $before = Expense::count();

        $first = now()->startOfMonth()->format('Y-m-d');
        $page = $this->actingAs($this->manager)->get('/expenses/duplicate-fixed')->assertOk()
            ->assertSee('Rent')->assertSee('Salaries')->assertDontSee('Electricity')->assertDontSee('Insurance')
            ->assertSee('value="'.$first.'"', false);

        $this->assertSame($before, Expense::count()); // drafts only: nothing saved yet

        $drafts = app(ExpenseService::class)->draftsFromLastMonth($this->manager, now());
        $this->assertCount(2, $drafts);
        $this->assertSame('lbp', $drafts[1]['currency']);
        $this->assertEqualsWithDelta(1_800_000, $drafts[1]['amount'], 0.5);
        $this->assertSame('outside', $drafts[0]['paid_from']);

        // The user keeps rent (edited) and drops salaries.
        $this->actingAs($this->manager)->post('/expenses/duplicate-fixed', ['rows' => [
            ['include' => 1, 'expense_category_id' => $this->cat('Rent'), 'date' => $first, 'currency' => 'usd', 'amount' => 850, 'payee' => '', 'notes' => '', 'paid_from' => 'outside'],
            ['expense_category_id' => $this->cat('Salaries'), 'date' => $first, 'currency' => 'lbp', 'amount' => 1_800_000, 'payee' => '', 'notes' => '', 'paid_from' => 'outside'],
        ]])->assertRedirect('/expenses')->assertSessionHasNoErrors();

        $this->assertSame($before + 1, Expense::count());
        $new = Expense::latest('id')->first();
        $this->assertSame($this->cat('Rent'), $new->expense_category_id);
        $this->assertEqualsWithDelta(850, (float) $new->amount_usd, 0.0001);
        $this->assertSame($first, $new->getRawOriginal('date'));

        $this->actingAs($this->manager)->post('/expenses/duplicate-fixed', ['rows' => [
            ['expense_category_id' => $this->cat('Rent'), 'date' => $first, 'currency' => 'usd', 'amount' => 1, 'paid_from' => 'outside'],
        ]])->assertSessionHasErrors('rows');
    }

    public function test_a_drawer_draft_without_an_open_shift_defaults_to_outside_and_cannot_be_forced(): void
    {
        $last = now()->subMonthNoOverflow()->startOfMonth()->addDays(3)->format('Y-m-d');
        $shift = Shift::create(['user_id' => $this->manager->id, 'opened_at' => now()->subMonth(), 'opening_cash_usd' => 0, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN]);
        $this->book('Rent', 800, $last, 'usd', 'drawer');
        $shift->update(['status' => Shift::STATUS_CLOSED]);

        $this->assertSame('outside', app(ExpenseService::class)->draftsFromLastMonth($this->manager, now())->first()['paid_from']);

        $this->actingAs($this->manager)->post('/expenses/duplicate-fixed', ['rows' => [
            ['include' => 1, 'expense_category_id' => $this->cat('Rent'), 'date' => now()->startOfMonth()->format('Y-m-d'), 'currency' => 'usd', 'amount' => 800, 'paid_from' => 'drawer'],
        ]])->assertSessionHasErrors('paid_from');
        $this->assertSame(1, Expense::count());
    }

    public function test_categories_are_managed_by_admins_in_the_settings_expenses_tab(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/settings?tab=expenses')->assertOk()
            ->assertSee('Expense categories')->assertSee('Rent')->assertSee('إيجار');

        // add
        $this->actingAs($admin)->post('/settings/expense-categories', [
            'name' => 'Cleaning', 'name_ar' => 'تنظيف', 'kind' => 'running', 'sort_order' => 20,
        ])->assertRedirect('/settings?tab=expenses');
        $cleaning = ExpenseCategory::where('name', 'Cleaning')->sole();
        $this->assertTrue($cleaning->is_active);

        // rename + re-kind + deactivate
        $this->actingAs($admin)->put("/settings/expense-categories/{$cleaning->id}", [
            'name' => 'Cleaning & hygiene', 'name_ar' => 'نظافة', 'kind' => 'fixed', 'sort_order' => 21,
        ])->assertRedirect('/settings?tab=expenses');
        $cleaning->refresh();
        $this->assertSame('Cleaning & hygiene', $cleaning->name);
        $this->assertSame('fixed', $cleaning->kind);
        $this->assertFalse($cleaning->is_active); // checkbox not sent = off

        // delete while unused is fine
        $this->actingAs($admin)->delete("/settings/expense-categories/{$cleaning->id}")->assertRedirect('/settings?tab=expenses');
        $this->assertNull(ExpenseCategory::find($cleaning->id));

        // a category with expenses (even soft-deleted ones) cannot be deleted, only deactivated
        $rent = ExpenseCategory::find($this->cat('Rent'));
        $e = $this->book('Rent', 10, now()->format('Y-m-d'));
        $this->actingAs($admin)->delete("/settings/expense-categories/{$rent->id}")->assertSessionHasErrors('category');
        app(ExpenseService::class)->delete($e, $this->manager);
        $this->actingAs($admin)->delete("/settings/expense-categories/{$rent->id}")->assertSessionHasErrors('category');
        $this->assertNotNull(ExpenseCategory::find($rent->id));

        $this->actingAs($admin)->put("/settings/expense-categories/{$rent->id}", [
            'name' => 'Rent', 'name_ar' => 'إيجار', 'kind' => 'fixed', 'sort_order' => 1, 'is_active' => 1,
        ])->assertSessionHasNoErrors();
        // create, update and delete of Cleaning, plus the update of Rent
        $this->assertSame(4, AuditLog::where('model_type', ExpenseCategory::class)->count());
    }

    public function test_managers_cannot_manage_categories_and_validation_applies(): void
    {
        $this->actingAs($this->manager)->post('/settings/expense-categories', ['name' => 'X', 'kind' => 'fixed'])->assertForbidden();

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post('/settings/expense-categories', ['name' => '', 'kind' => 'fixed'])->assertSessionHasErrors('name');
        $this->actingAs($admin)->post('/settings/expense-categories', ['name' => 'Odd', 'kind' => 'sometimes'])->assertSessionHasErrors('kind');
    }

    public function test_cashier_and_stock_roles_are_refused_everywhere_in_the_module(): void
    {
        $e = $this->book('Rent', 10, now()->format('Y-m-d'));

        foreach ([User::factory()->create(), User::factory()->stock()->create()] as $user) {
            $this->actingAs($user);
            $this->get('/expenses')->assertForbidden();
            $this->get('/expenses/create')->assertForbidden();
            $this->post('/expenses', $this->payload())->assertForbidden();
            $this->get("/expenses/{$e->id}/edit")->assertForbidden();
            $this->put("/expenses/{$e->id}", $this->payload())->assertForbidden();
            $this->delete("/expenses/{$e->id}")->assertForbidden();
            $this->get('/expenses/export')->assertForbidden();
            $this->get('/expenses/duplicate-fixed')->assertForbidden();
            $this->post('/expenses/duplicate-fixed', ['rows' => []])->assertForbidden();
            $this->get('/reports/financial/expenses')->assertForbidden();
            $this->post('/settings/expense-categories', ['name' => 'X', 'kind' => 'fixed'])->assertForbidden();
        }

        $this->assertSame(1, Expense::count());
        foreach (['admin', 'manager'] as $role) {
            $this->actingAs(User::factory()->{$role}()->create())->get('/expenses')->assertOk();
        }
    }
}
