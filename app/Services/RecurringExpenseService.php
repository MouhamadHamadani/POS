<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\RecurringExpense;
use App\Models\User;
use Carbon\Carbon;
use Generator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Recurring expenses: the templates' own CRUD, when each one is due, and the idempotent
 * generator that books the real expenses.
 *
 * generateDue() is safe to call from anywhere, as often as you like (the daily scheduler, app
 * launch, every sign-in, the "Generate now" button). Two things make it so: a template's
 * `last_generated_for` is a cursor, so a run with nothing due is one small query and no writes,
 * and the unique (recurring_expense_id, period_date) pair on `expenses` means a second run, or a
 * second process racing the first, can never book an occurrence twice.
 *
 * A generated expense is an ordinary `outside` expense (it never touches the shift drawer),
 * dated on its occurrence, with no author. Occurrences missed while the app was closed are
 * booked on the next run, each on its own date.
 */
class RecurringExpenseService
{
    public const SOURCE_SCHEDULE = 'schedule';
    public const SOURCE_LAUNCH = 'launch';
    public const SOURCE_LOGIN = 'login';
    public const SOURCE_MANUAL = 'manual';

    /** What an audit entry records about a template. */
    private const AUDITED = [
        'expense_category_id', 'description', 'amount_usd', 'amount_lbp', 'currency', 'frequency',
        'day_of_week', 'day_of_month', 'start_date', 'end_date', 'is_active',
    ];

    public function __construct(private readonly ExpenseService $expenses) {}

    // ---- reading -------------------------------------------------------------

    public function list(): Collection
    {
        return RecurringExpense::query()
            ->with('category:id,name,name_ar,kind')
            ->orderByDesc('is_active')->orderBy('start_date')->orderBy('id')
            ->get();
    }

    /** The next occurrence not yet booked (today's included, and overdue ones), or null once the template has run out. */
    public function nextDue(RecurringExpense $t): ?Carbon
    {
        $next = $this->occurrencesFrom($t, $this->firstDay($t))->current();

        return $t->end_date && $next->gt($t->end_date) ? null : $next;
    }

    /**
     * Every unbooked occurrence up to `$today`, oldest first, within the template's end date.
     *
     * @return list<Carbon>
     */
    public function dueDates(RecurringExpense $t, Carbon $today): array
    {
        $limit = $t->end_date && $t->end_date->lt($today) ? $t->end_date : $today;
        $due = [];

        foreach ($this->occurrencesFrom($t, $this->firstDay($t)) as $date) {
            if ($date->gt($limit)) {
                break;
            }
            $due[] = $date;
        }

        return $due;
    }

    /** Where to look from: the start date, or the day after the last occurrence handled. */
    private function firstDay(RecurringExpense $t): Carbon
    {
        $cursor = $t->last_generated_for?->copy()->addDay();

        return $cursor && $cursor->gt($t->start_date) ? $cursor : $t->start_date->copy();
    }

    /**
     * The template's occurrences on or after `$from`, in date order, without end. A monthly day
     * that the month is too short for (the 31st in April) falls on the month's last day, and a
     * yearly 29 February on the 28th in other years.
     *
     * @return Generator<int, Carbon>
     */
    private function occurrencesFrom(RecurringExpense $t, Carbon $from): Generator
    {
        $from = $from->copy()->startOfDay();

        if ($t->frequency === RecurringExpense::WEEKLY) {
            $date = $from->copy()->addDays(($t->day_of_week - $from->dayOfWeekIso + 7) % 7);
            while (true) {
                yield $date->copy();
                $date->addWeek();
            }
        }

        $monthly = $t->frequency === RecurringExpense::MONTHLY;
        $month = $from->copy()->startOfMonth();
        while (true) {
            $day = $monthly ? $t->day_of_month : $t->start_date->day;
            $date = $month->copy()->day(min($day, $month->daysInMonth));

            if ($date->gte($from) && ($monthly || $month->month === $t->start_date->month)) {
                yield $date;
            }
            $month->addMonthNoOverflow();
        }
    }

    // ---- generating ----------------------------------------------------------

    /**
     * Book every occurrence that has come due up to today, for every active template.
     *
     * @return array{expenses: int, templates: int} how many expenses were booked, from how many templates
     */
    public function generateDue(?Carbon $today = null, ?User $actor = null, string $source = self::SOURCE_SCHEDULE): array
    {
        // Off means hidden: don't book expenses nobody can see. Nothing is lost, the cursor stays put.
        if (! feature('expenses')) {
            return ['expenses' => 0, 'templates' => 0];
        }

        $today = ($today ?? now())->copy()->startOfDay();
        $day = $today->toDateString();
        $booked = [];

        $templates = RecurringExpense::query()
            ->where('is_active', true)
            ->where('start_date', '<=', $day)
            ->where(fn ($q) => $q->whereNull('last_generated_for')->orWhere('last_generated_for', '<', $day))
            ->get();

        foreach ($templates as $template) {
            $dates = $this->dueDates($template, $today);
            if ($dates === []) {
                continue;
            }

            $count = DB::transaction(function () use ($template, $dates) {
                $count = 0;
                foreach ($dates as $date) {
                    $count += (int) $this->book($template, $date);
                }
                $template->update(['last_generated_for' => end($dates)]);

                return $count;
            });

            if ($count > 0) {
                $booked[$template->id] = $count;
            }
        }

        if ($booked !== []) {
            AuditLog::record($actor?->id, 'recurring_expenses_generated', RecurringExpense::class, null, null, [
                'source' => $source,
                'through' => $day,
                'expenses' => array_sum($booked),
                'by_template' => $booked,
            ]);
        }

        return ['expenses' => array_sum($booked), 'templates' => count($booked)];
    }

    /** Book one occurrence; false when it is already there (booked earlier, or since deleted). */
    private function book(RecurringExpense $t, Carbon $date): bool
    {
        $period = $date->toDateString();

        if (Expense::withTrashed()->where('recurring_expense_id', $t->id)->where('period_date', $period)->exists()) {
            return false;
        }

        // The typed amount is the truth; the other currency follows today's rate.
        [$usd, $lbp] = $this->expenses->amounts($t->currency, $t->enteredAmount());

        try {
            Expense::create([
                'expense_category_id' => $t->expense_category_id,
                'date' => $period,
                'amount_usd' => $usd,
                'amount_lbp' => $lbp,
                'currency' => $t->currency,
                'notes' => $t->description,
                'paid_from' => Expense::PAID_OUTSIDE,
                'created_by' => null,
                'recurring_expense_id' => $t->id,
                'period_date' => $period,
            ]);
        } catch (UniqueConstraintViolationException) {
            return false; // another process booked it between the check and the insert
        }

        return true;
    }

    // ---- writing -------------------------------------------------------------

    public function create(array $data, User $user): RecurringExpense
    {
        return DB::transaction(function () use ($data, $user) {
            $template = RecurringExpense::create($this->attributes($data) + ['created_by' => $user->id]);

            AuditLog::record($user->id, 'create', RecurringExpense::class, $template->id, null, $template->only(self::AUDITED));

            return $template;
        });
    }

    /** Only future occurrences change: expenses already booked are left exactly as they are. */
    public function update(RecurringExpense $template, array $data, User $user): RecurringExpense
    {
        return DB::transaction(function () use ($template, $data, $user) {
            $old = $template->only(self::AUDITED);
            $template->update($this->attributes($data));

            AuditLog::record($user->id, 'update', RecurringExpense::class, $template->id, $old, $template->only(self::AUDITED));

            return $template;
        });
    }

    public function setActive(RecurringExpense $template, bool $active, User $user): void
    {
        $template->update(['is_active' => $active]);

        AuditLog::record($user->id, $active ? 'resume' : 'pause', RecurringExpense::class, $template->id, ['is_active' => ! $active], ['is_active' => $active]);
    }

    /** Soft delete: the expenses it already booked keep their link, and are not touched. */
    public function delete(RecurringExpense $template, User $user): void
    {
        DB::transaction(function () use ($template, $user) {
            $old = $template->only(self::AUDITED);
            $template->delete();

            AuditLog::record($user->id, 'delete', RecurringExpense::class, $template->id, $old, null);
        });
    }

    /** Form input to columns: the amount in both currencies, and only the day field its frequency uses. */
    private function attributes(array $data): array
    {
        [$usd, $lbp] = $this->expenses->amounts($data['currency'], (float) $data['amount']);
        $weekly = $data['frequency'] === RecurringExpense::WEEKLY;
        $monthly = $data['frequency'] === RecurringExpense::MONTHLY;

        return [
            'expense_category_id' => $data['expense_category_id'],
            'description' => ($data['description'] ?? null) ?: null,
            'amount_usd' => $usd,
            'amount_lbp' => $lbp,
            'currency' => $data['currency'],
            'frequency' => $data['frequency'],
            'day_of_week' => $weekly ? (int) $data['day_of_week'] : null,
            'day_of_month' => $monthly ? (int) $data['day_of_month'] : null,
            'start_date' => $data['start_date'],
            'end_date' => ($data['end_date'] ?? null) ?: null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }
}
