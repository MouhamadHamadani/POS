<?php

namespace Tests\Feature\Expenses;

use App\Models\AuditLog;
use App\Models\CashMovement;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\RecurringExpense;
use App\Models\Setting;
use App\Models\User;
use App\Providers\NativeAppServiceProvider;
use App\Services\RecurringExpenseService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Recurring expenses: when each kind of template is due, that the generator books exactly what
 * is due (missed occurrences included) exactly once, and who may run the module.
 */
class RecurringExpensesTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private RecurringExpenseService $recurring;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('exchange_rate', 90000, 'currency', 'float');
        Setting::set('lbp_rounding_step', 1000, 'currency', 'float');
        $this->manager = User::factory()->manager()->create();
        $this->recurring = app(RecurringExpenseService::class);
    }

    private function cat(string $name = 'Rent'): int
    {
        return (int) ExpenseCategory::where('name', $name)->value('id');
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'expense_category_id' => $this->cat(),
            'description' => 'Shop rent',
            'currency' => 'usd',
            'amount' => 500,
            'frequency' => 'monthly',
            'day_of_month' => 1,
            'start_date' => '2026-05-01',
        ], $over);
    }

    private function template(array $over = []): RecurringExpense
    {
        return $this->recurring->create($this->payload($over), $this->manager);
    }

    private function on(string $date): Carbon
    {
        return Carbon::parse($date)->startOfDay();
    }

    /** @return list<string> the dates of the expenses a template booked, oldest first */
    private function booked(RecurringExpense $t): array
    {
        return Expense::where('recurring_expense_id', $t->id)->orderBy('date')->get()->map(fn ($e) => $e->date->toDateString())->all();
    }

    /** @return list<string> */
    private function dates(RecurringExpense $t, string $today): array
    {
        return array_map(fn (Carbon $d) => $d->toDateString(), $this->recurring->dueDates($t->fresh(), $this->on($today)));
    }

    // ---- when is it due ----------------------------------------------------------------

    public function test_weekly_falls_on_its_weekday_from_the_start_date(): void
    {
        $monday = $this->template(['frequency' => 'weekly', 'day_of_week' => 1, 'day_of_month' => null, 'start_date' => '2026-10-05']);

        $this->assertSame(['2026-10-05', '2026-10-12', '2026-10-19', '2026-10-26'], $this->dates($monday, '2026-10-28'));
        $this->assertSame([], $this->dates($monday, '2026-10-04'));

        // Starts on a Wednesday but runs on Fridays: the first one is that week's Friday.
        $friday = $this->template(['frequency' => 'weekly', 'day_of_week' => 5, 'day_of_month' => null, 'start_date' => '2026-10-07']);
        $this->assertSame(['2026-10-09', '2026-10-16', '2026-10-23'], $this->dates($friday, '2026-10-28'));

        // Sunday is 7.
        $sunday = $this->template(['frequency' => 'weekly', 'day_of_week' => 7, 'day_of_month' => null, 'start_date' => '2026-10-01']);
        $this->assertSame(['2026-10-04', '2026-10-11', '2026-10-18', '2026-10-25'], $this->dates($sunday, '2026-10-28'));
    }

    public function test_monthly_falls_on_its_day_and_does_not_book_before_the_start_date(): void
    {
        $t = $this->template(['day_of_month' => 15, 'start_date' => '2026-08-20']);

        $this->assertSame(['2026-09-15', '2026-10-15'], $this->dates($t, '2026-10-28'));
        $this->assertSame(['2026-09-15'], $this->dates($t, '2026-10-14'));
    }

    public function test_the_31st_falls_on_the_last_day_of_a_shorter_month(): void
    {
        $t = $this->template(['day_of_month' => 31, 'start_date' => '2026-01-01']);

        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31', '2026-06-30'], $this->dates($t, '2026-06-30'));

        // ...and the 29th of a leap February is the 29th.
        $leap = $this->template(['day_of_month' => 31, 'start_date' => '2024-01-01']);
        $this->assertSame(['2024-01-31', '2024-02-29', '2024-03-31'], $this->dates($leap, '2024-03-31'));
    }

    public function test_the_30th_in_february_and_the_29th_in_a_common_year(): void
    {
        $this->assertSame(['2026-02-28'], $this->dates($this->template(['day_of_month' => 30, 'start_date' => '2026-02-01']), '2026-02-28'));
        $dates = $this->dates($this->template(['day_of_month' => 29, 'start_date' => '2025-02-01']), '2026-02-28');
        $this->assertSame(['2025-02-28', '2025-03-29'], array_slice($dates, 0, 2));
        $this->assertSame('2026-02-28', end($dates));
    }

    public function test_yearly_repeats_on_the_start_dates_day_and_month(): void
    {
        $t = $this->template(['frequency' => 'yearly', 'day_of_month' => null, 'start_date' => '2025-03-15']);

        $this->assertSame(['2025-03-15'], $this->dates($t, '2026-03-14'));
        $this->assertSame(['2025-03-15', '2026-03-15'], $this->dates($t, '2026-03-15'));
        $this->assertSame(['2025-03-15', '2026-03-15'], $this->dates($t, '2026-12-31'));
    }

    public function test_a_yearly_29_february_falls_on_the_28th_in_other_years(): void
    {
        $t = $this->template(['frequency' => 'yearly', 'day_of_month' => null, 'start_date' => '2024-02-29']);

        $this->assertSame(['2024-02-29', '2025-02-28', '2026-02-28', '2027-02-28'], $this->dates($t, '2027-03-01'));
    }

    public function test_the_next_due_date(): void
    {
        $t = $this->template(['day_of_month' => 15, 'start_date' => '2026-10-20']);
        $this->assertSame('2026-11-15', $this->recurring->nextDue($t)->toDateString());

        $this->recurring->generateDue($this->on('2026-11-15'));
        $this->assertSame('2026-12-15', $this->recurring->nextDue($t->fresh())->toDateString());

        $ended = $this->template(['start_date' => '2026-01-01', 'end_date' => '2026-03-15']);
        $this->recurring->generateDue($this->on('2026-10-28'));
        $this->assertNull($this->recurring->nextDue($ended->fresh()));
    }

    // ---- generating --------------------------------------------------------------------

    public function test_missed_months_are_all_booked_each_on_its_own_date_as_plain_outside_expenses(): void
    {
        $t = $this->template(['start_date' => '2026-05-01']);

        $result = $this->recurring->generateDue($this->on('2026-10-28'), source: 'launch');

        $this->assertSame(['expenses' => 6, 'templates' => 1], $result);
        $this->assertSame(['2026-05-01', '2026-06-01', '2026-07-01', '2026-08-01', '2026-09-01', '2026-10-01'], $this->booked($t));

        $e = Expense::where('recurring_expense_id', $t->id)->orderBy('date')->first();
        $this->assertSame($this->cat(), $e->expense_category_id);
        $this->assertSame('Shop rent', $e->notes);
        $this->assertSame('usd', $e->currency);
        $this->assertEqualsWithDelta(500.0, (float) $e->amount_usd, 0.0001);
        $this->assertEqualsWithDelta(45000000.0, (float) $e->amount_lbp, 0.01);
        $this->assertSame(Expense::PAID_OUTSIDE, $e->paid_from);
        $this->assertNull($e->created_by, 'no author: the system booked it');
        $this->assertNull($e->shift_id);
        $this->assertNull($e->cash_movement_id);
        $this->assertSame('2026-05-01', $e->period_date);
        $this->assertTrue($e->isAutomatic());
        $this->assertFalse($e->isLocked());
        $this->assertSame(0, CashMovement::count(), 'the drawer is never touched');
    }

    public function test_rerunning_never_duplicates(): void
    {
        $t = $this->template(['start_date' => '2026-08-01']);

        $this->recurring->generateDue($this->on('2026-10-28'));
        $again = $this->recurring->generateDue($this->on('2026-10-28'));

        $this->assertSame(['expenses' => 0, 'templates' => 0], $again);
        $this->assertSame(3, Expense::where('recurring_expense_id', $t->id)->count());

        // Even with the cursor lost (a restored backup, a hand edit) the unique pair holds.
        DB::table('recurring_expenses')->where('id', $t->id)->update(['last_generated_for' => null]);
        $this->assertSame(['expenses' => 0, 'templates' => 0], $this->recurring->generateDue($this->on('2026-10-28')));
        $this->assertSame(3, Expense::where('recurring_expense_id', $t->id)->count());

        // ...and the database itself refuses a second row for the same occurrence.
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        Expense::create([
            'expense_category_id' => $this->cat(), 'date' => '2026-08-01', 'amount_usd' => 1, 'amount_lbp' => 90000, 'currency' => 'usd',
            'paid_from' => 'outside', 'recurring_expense_id' => $t->id, 'period_date' => '2026-08-01',
        ]);
    }

    public function test_an_automatic_expense_the_owner_deleted_stays_deleted(): void
    {
        $t = $this->template(['start_date' => '2026-09-01']);
        $this->recurring->generateDue($this->on('2026-10-28'));

        Expense::where('recurring_expense_id', $t->id)->orderBy('date')->first()->delete();
        DB::table('recurring_expenses')->where('id', $t->id)->update(['last_generated_for' => null]);
        $this->recurring->generateDue($this->on('2026-10-28'));

        $this->assertSame(['2026-10-01'], $this->booked($t));
        $this->assertSame(2, Expense::withTrashed()->where('recurring_expense_id', $t->id)->count());
    }

    public function test_it_catches_up_from_where_it_left_off_and_books_today_itself(): void
    {
        $t = $this->template(['start_date' => '2026-08-01']);
        $this->recurring->generateDue($this->on('2026-09-15'));
        $this->assertSame(['2026-08-01', '2026-09-01'], $this->booked($t));

        $this->recurring->generateDue($this->on('2026-10-31'));
        $this->assertSame(['2026-08-01', '2026-09-01', '2026-10-01'], $this->booked($t));
        $this->assertSame('2026-10-01', $t->fresh()->last_generated_for->toDateString());

        // Due today counts: the 1st itself.
        $this->recurring->generateDue($this->on('2026-11-01'));
        $this->assertContains('2026-11-01', $this->booked($t));
    }

    public function test_nothing_is_booked_before_the_start_date_or_while_paused(): void
    {
        $future = $this->template(['start_date' => '2026-12-01']);
        $paused = $this->template(['start_date' => '2026-08-01']);
        $this->recurring->setActive($paused, false, $this->manager);

        $this->assertSame(['expenses' => 0, 'templates' => 0], $this->recurring->generateDue($this->on('2026-10-28')));
        $this->assertSame([], $this->booked($future));
        $this->assertSame([], $this->booked($paused));

        // Resuming books what fell due while it was paused.
        $this->recurring->setActive($paused, true, $this->manager);
        $this->recurring->generateDue($this->on('2026-10-28'));
        $this->assertSame(['2026-08-01', '2026-09-01', '2026-10-01'], $this->booked($paused));
    }

    public function test_an_end_date_stops_it_and_a_template_that_ended_books_nothing_more(): void
    {
        $t = $this->template(['start_date' => '2026-01-01', 'end_date' => '2026-03-15']);

        $this->recurring->generateDue($this->on('2026-10-28'));

        $this->assertSame(['2026-01-01', '2026-02-01', '2026-03-01'], $this->booked($t));
        $this->assertSame(['expenses' => 0, 'templates' => 0], $this->recurring->generateDue($this->on('2026-12-01')));

        // The end date itself is still a day it can fall on.
        $edge = $this->template(['start_date' => '2026-09-01', 'end_date' => '2026-10-01']);
        $this->recurring->generateDue($this->on('2026-10-28'));
        $this->assertSame(['2026-09-01', '2026-10-01'], $this->booked($edge));
    }

    public function test_an_lbp_template_keeps_its_lira_amount_and_follows_the_rate_of_the_day(): void
    {
        $t = $this->template(['currency' => 'lbp', 'amount' => 4500000, 'start_date' => '2026-10-01']);

        $this->recurring->generateDue($this->on('2026-10-28'));
        $e = Expense::where('recurring_expense_id', $t->id)->sole();
        $this->assertSame('lbp', $e->currency);
        $this->assertEqualsWithDelta(4500000.0, (float) $e->amount_lbp, 0.01);
        $this->assertEqualsWithDelta(50.0, (float) $e->amount_usd, 0.01);

        Setting::set('exchange_rate', 100000, 'currency', 'float');
        $this->recurring->generateDue($this->on('2026-11-28'));
        $next = Expense::where('recurring_expense_id', $t->id)->orderByDesc('date')->first();
        $this->assertEqualsWithDelta(4500000.0, (float) $next->amount_lbp, 0.01);
        $this->assertEqualsWithDelta(45.0, (float) $next->amount_usd, 0.01);
        $this->assertEqualsWithDelta(50.0, (float) $e->fresh()->amount_usd, 0.01, 'the earlier one is not repriced');
    }

    public function test_editing_the_amount_only_affects_the_expenses_booked_afterwards(): void
    {
        $t = $this->template(['start_date' => '2026-09-01']);
        $this->recurring->generateDue($this->on('2026-09-30'));

        $this->actingAs($this->manager)->put(route('expenses.recurring.update', $t), $this->payload(['amount' => 650, 'start_date' => '2026-09-01']))
            ->assertRedirect(route('expenses.recurring.index'));
        $this->recurring->generateDue($this->on('2026-10-28'));

        $amounts = Expense::where('recurring_expense_id', $t->id)->orderBy('date')->pluck('amount_usd')->map(fn ($v) => (float) $v)->all();
        $this->assertSame([500.0, 650.0], $amounts);
    }

    public function test_the_expense_module_being_off_books_nothing_and_loses_nothing(): void
    {
        $t = $this->template(['start_date' => '2026-09-01']);
        Setting::set('feature_overrides', ['expenses' => false], 'license', 'json');

        $this->assertSame(['expenses' => 0, 'templates' => 0], $this->recurring->generateDue($this->on('2026-10-28')));
        $this->assertSame([], $this->booked($t));

        Setting::set('feature_overrides', [], 'license', 'json');
        $this->recurring->generateDue($this->on('2026-10-28'));
        $this->assertSame(['2026-09-01', '2026-10-01'], $this->booked($t));
    }

    public function test_a_run_with_nothing_due_is_cheap_and_writes_nothing(): void
    {
        $this->template(['start_date' => '2026-10-01']);
        $this->recurring->generateDue($this->on('2026-10-28'));
        $audits = AuditLog::count();

        DB::enableQueryLog();
        $this->recurring->generateDue($this->on('2026-10-29'));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(3, count($queries));
        $this->assertSame([], array_values(array_filter($queries, fn ($q) => preg_match('/^(insert|update|delete)/i', $q['query']))));
        $this->assertSame($audits, AuditLog::count());
    }

    // ---- triggers ------------------------------------------------------------------------

    public function test_the_command_the_scheduler_runs_books_what_is_due(): void
    {
        $t = $this->template(['start_date' => now()->subMonths(2)->startOfMonth()->toDateString()]);

        $this->artisan('pos:generate-recurring-expenses')->expectsOutputToContain('Booked')->assertSuccessful();
        $this->assertGreaterThanOrEqual(2, Expense::where('recurring_expense_id', $t->id)->count());

        $this->artisan('pos:generate-recurring-expenses')->expectsOutputToContain('No recurring expenses were due')->assertSuccessful();
    }

    public function test_the_daily_schedule_is_registered(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('pos:generate-recurring-expenses')->assertSuccessful();
    }

    public function test_the_app_launch_catches_up(): void
    {
        $t = $this->template(['start_date' => now()->subMonths(3)->startOfMonth()->toDateString()]);

        (new \ReflectionMethod(NativeAppServiceProvider::class, 'bootstrapDatabase'))->invoke(new NativeAppServiceProvider);

        $this->assertGreaterThanOrEqual(3, Expense::where('recurring_expense_id', $t->id)->count());
        $log = AuditLog::where('action', 'recurring_expenses_generated')->sole();
        $this->assertSame('launch', $log->new_values['source']);
    }

    public function test_signing_in_catches_up_and_never_blocks_the_sign_in(): void
    {
        $t = $this->template(['start_date' => now()->subMonths(3)->startOfMonth()->toDateString()]);
        $cashier = User::factory()->create(['username' => 'till1', 'password' => 'secret123']);

        $this->post('/login', ['login' => 'till1', 'password' => 'secret123'])->assertRedirect();

        $this->assertGreaterThanOrEqual(3, Expense::where('recurring_expense_id', $t->id)->count());
        $log = AuditLog::where('action', 'recurring_expenses_generated')->sole();
        $this->assertSame('login', $log->new_values['source']);
        $this->assertSame($cashier->id, $log->user_id);

        // A second sign-in finds nothing to do.
        $this->post('/logout');
        $this->post('/login', ['login' => 'till1', 'password' => 'secret123'])->assertRedirect();
        $this->assertSame(1, AuditLog::where('action', 'recurring_expenses_generated')->count());
    }

    // ---- audit ---------------------------------------------------------------------------

    public function test_every_change_and_every_batch_is_audited(): void
    {
        $t = $this->template(['start_date' => '2026-09-01']);
        $this->assertSame(1, AuditLog::where('action', 'create')->where('model_type', RecurringExpense::class)->count());

        $this->recurring->update($t, $this->payload(['amount' => 600, 'start_date' => '2026-09-01']), $this->manager);
        $update = AuditLog::where('action', 'update')->where('model_type', RecurringExpense::class)->sole();
        $this->assertEquals(500, $update->old_values['amount_usd']);
        $this->assertEquals(600, $update->new_values['amount_usd']);

        $this->recurring->setActive($t, false, $this->manager);
        $this->recurring->setActive($t, true, $this->manager);
        $this->assertSame(1, AuditLog::where('action', 'pause')->count());
        $this->assertSame(1, AuditLog::where('action', 'resume')->count());

        $this->recurring->generateDue($this->on('2026-10-28'), $this->manager, 'manual');
        $batch = AuditLog::where('action', 'recurring_expenses_generated')->sole();
        $this->assertSame($this->manager->id, $batch->user_id);
        $this->assertSame(['source' => 'manual', 'through' => '2026-10-28', 'expenses' => 2, 'by_template' => [$t->id => 2]], $batch->new_values);

        $this->recurring->delete($t, $this->manager);
        $this->assertSame(1, AuditLog::where('action', 'delete')->where('model_type', RecurringExpense::class)->count());
    }

    public function test_a_data_reset_clears_templates_and_what_they_booked(): void
    {
        $this->template(['start_date' => '2026-09-01']);
        $this->recurring->generateDue($this->on('2026-10-28'));
        $this->assertSame(2, Expense::count());

        $this->artisan('pos:reset-data --force')->assertSuccessful();

        $this->assertSame(0, RecurringExpense::withTrashed()->count());
        $this->assertSame(0, Expense::withTrashed()->count());
    }

    // ---- the screens ---------------------------------------------------------------------

    public function test_the_recurring_tab_lists_templates_with_their_next_due_date_and_status(): void
    {
        $active = $this->template(['description' => 'Landlord', 'day_of_month' => 5, 'start_date' => '2026-01-01']);
        $this->recurring->generateDue(now()->startOfDay());
        $paused = $this->template(['description' => 'Old cleaner', 'frequency' => 'weekly', 'day_of_week' => 3, 'day_of_month' => null]);
        $this->recurring->setActive($paused, false, $this->manager);

        $next = $this->recurring->nextDue($active->fresh())->format('Y-m-d');

        $this->actingAs($this->manager)->get(route('expenses.recurring.index'))->assertOk()
            ->assertSee('Recurring expenses')->assertSee('Landlord')->assertSee('Old cleaner')
            ->assertSee($next)->assertSee('Monthly, day 5')->assertSee('Every Wednesday')
            ->assertSee('Active')->assertSee('Paused')->assertSee('Generate now');
    }

    public function test_the_expense_list_badges_automatic_expenses_and_has_the_recurring_tab(): void
    {
        $t = $this->template(['start_date' => now()->startOfMonth()->toDateString()]);
        $this->recurring->generateDue(now()->startOfDay());

        $this->actingAs($this->manager)->get(route('expenses.index'))->assertOk()
            ->assertSee('Auto')->assertSee('Booked automatically by a recurring expense')
            ->assertSee(route('expenses.recurring.index'), false);
    }

    public function test_deleting_a_template_keeps_its_expenses_and_their_badge(): void
    {
        $t = $this->template(['start_date' => now()->startOfMonth()->toDateString()]);
        $this->recurring->generateDue(now()->startOfDay());

        $this->actingAs($this->manager)->delete(route('expenses.recurring.destroy', $t))
            ->assertRedirect(route('expenses.recurring.index'));

        $this->assertSoftDeleted('recurring_expenses', ['id' => $t->id]);
        $this->assertSame(1, Expense::where('recurring_expense_id', $t->id)->count());
        $this->assertSame($t->id, Expense::where('recurring_expense_id', $t->id)->first()->recurringExpense->id);
        $this->actingAs($this->manager)->get(route('expenses.index'))->assertOk()->assertSee('Auto');
        $this->actingAs($this->manager)->get(route('expenses.recurring.index'))->assertOk()->assertDontSee('Shop rent');

        // And it is not booked again.
        $this->assertSame(['expenses' => 0, 'templates' => 0], $this->recurring->generateDue(now()->addMonths(3)->startOfDay()));
    }

    public function test_the_form_saves_validates_and_pauses_and_resumes(): void
    {
        $this->actingAs($this->manager)->get(route('expenses.recurring.create'))->assertOk()->assertSee('New recurring expense');

        $this->post(route('expenses.recurring.store'), $this->payload(['description' => 'From the form']))
            ->assertRedirect(route('expenses.recurring.index'));
        $t = RecurringExpense::where('description', 'From the form')->sole();
        $this->assertSame($this->manager->id, $t->created_by);
        $this->assertNull($t->day_of_week, 'a monthly template keeps no weekday');

        $this->get(route('expenses.recurring.edit', $t))->assertOk()->assertSee('From the form');

        $this->post(route('expenses.recurring.toggle', $t))->assertRedirect();
        $this->assertFalse($t->fresh()->is_active);
        $this->post(route('expenses.recurring.toggle', $t))->assertRedirect();
        $this->assertTrue($t->fresh()->is_active);

        // Validation: a weekly needs its weekday, a monthly its day, the end cannot precede the start.
        $this->post(route('expenses.recurring.store'), $this->payload(['frequency' => 'weekly', 'day_of_week' => null, 'day_of_month' => null]))->assertSessionHasErrors('day_of_week');
        $this->post(route('expenses.recurring.store'), $this->payload(['day_of_month' => null]))->assertSessionHasErrors('day_of_month');
        $this->post(route('expenses.recurring.store'), $this->payload(['day_of_month' => 32]))->assertSessionHasErrors('day_of_month');
        $this->post(route('expenses.recurring.store'), $this->payload(['end_date' => '2026-01-01']))->assertSessionHasErrors('end_date');
        $this->post(route('expenses.recurring.store'), $this->payload(['frequency' => 'daily']))->assertSessionHasErrors('frequency');
        $this->post(route('expenses.recurring.store'), $this->payload(['amount' => 0]))->assertSessionHasErrors('amount');
        $this->post(route('expenses.recurring.store'), $this->payload(['expense_category_id' => 9999]))->assertSessionHasErrors('expense_category_id');
        $this->assertSame(1, RecurringExpense::count());
    }

    public function test_generate_now_books_what_is_due_and_says_so(): void
    {
        $t = $this->template(['start_date' => now()->subMonths(2)->startOfMonth()->toDateString()]);

        $this->actingAs($this->manager)->post(route('expenses.recurring.generate'))
            ->assertRedirect()->assertSessionHas('success');
        $this->assertGreaterThanOrEqual(2, Expense::where('recurring_expense_id', $t->id)->count());
        $this->assertSame('manual', AuditLog::where('action', 'recurring_expenses_generated')->sole()->new_values['source']);

        $this->actingAs($this->manager)->post(route('expenses.recurring.generate'))
            ->assertSessionHas('success', __('messages.recurring_none_due'));
    }

    public function test_the_screens_are_bilingual(): void
    {
        $this->template(['description' => 'Landlord']);
        $this->manager->update(['language' => 'ar']);

        $this->actingAs($this->manager)->get(route('expenses.recurring.index'))->assertOk()
            ->assertSee('المصاريف المتكررة')->assertSee('سجّل الآن')->assertSee('شهرياً');
        $this->get(route('expenses.recurring.create'))->assertOk()->assertSee('مصروف متكرر جديد')->assertSee('الاثنين');
    }

    // ---- who, and which plans -----------------------------------------------------------

    public function test_admin_manager_and_super_admin_manage_templates_nobody_else_does(): void
    {
        $t = $this->template();
        $routes = [
            ['get', route('expenses.recurring.index')],
            ['get', route('expenses.recurring.create')],
            ['post', route('expenses.recurring.store'), $this->payload()],
            ['get', route('expenses.recurring.edit', $t)],
            ['put', route('expenses.recurring.update', $t), $this->payload()],
            ['post', route('expenses.recurring.toggle', $t)],
            ['post', route('expenses.recurring.generate')],
            ['delete', route('expenses.recurring.destroy', $t)],
        ];

        foreach ([User::ROLE_CASHIER, User::ROLE_STOCK] as $role) {
            $user = User::factory()->create(['role' => $role]);
            foreach ($routes as $route) {
                $this->actingAs($user)->{$route[0]}($route[1], $route[2] ?? [])->assertForbidden();
            }
        }

        foreach ([User::factory()->admin()->create(), User::factory()->superAdmin()->create(), $this->manager] as $user) {
            $this->actingAs($user)->get(route('expenses.recurring.index'))->assertOk();
            $this->actingAs($user)->post(route('expenses.recurring.generate'))->assertRedirect();
        }
        $this->actingAs(User::factory()->admin()->create())->post(route('expenses.recurring.store'), $this->payload(['description' => 'By the admin']))->assertRedirect();
        $this->assertTrue(RecurringExpense::where('description', 'By the admin')->exists());
    }

    public function test_the_whole_module_follows_the_expenses_feature(): void
    {
        $t = $this->template();
        Setting::set('feature_overrides', ['expenses' => false], 'license', 'json');

        $this->actingAs($this->manager)->get(route('expenses.recurring.index'))->assertRedirect()->assertSessionHasErrors('plan');
        $this->actingAs($this->manager)->post(route('expenses.recurring.generate'))->assertRedirect()->assertSessionHasErrors('plan');
        $this->actingAs($this->manager)->post(route('expenses.recurring.store'), $this->payload())->assertSessionHasErrors('plan');
        $this->actingAs($this->manager)->delete(route('expenses.recurring.destroy', $t))->assertSessionHasErrors('plan');
        $this->assertSame(1, RecurringExpense::count());
    }

    public function test_every_plan_has_it_because_every_plan_has_expenses(): void
    {
        foreach (['starter', 'standard', 'premium'] as $plan) {
            Setting::set('tier', $plan, 'license');
            $this->actingAs($this->manager)->get(route('expenses.recurring.index'))->assertOk();
        }
    }
}
