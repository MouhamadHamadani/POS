<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CashMovement;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Shift;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Expenses and their link to the cash drawer.
 *
 * An `outside` expense (bank, the owner's pocket) is a record for reports. A
 * `drawer` expense also owns a cash_movements row of type `out` on the paying
 * user's open shift, created and removed in the same transaction as the expense,
 * so ShiftService::totals() sees it. Once that shift is closed the pair is frozen.
 */
class ExpenseService
{
    /** What an audit entry records about an expense. */
    private const AUDITED = [
        'expense_category_id', 'date', 'amount_usd', 'amount_lbp', 'currency',
        'payee', 'reference', 'notes', 'paid_from', 'shift_id',
    ];

    public function __construct(
        private readonly CurrencyService $currency,
        private readonly ShiftService $shifts,
    ) {}

    // ---- reading -------------------------------------------------------------

    /** @return array{from: Carbon, to: Carbon, category_id: ?int, kind: ?string, paid_from: ?string} */
    public function filters(array $input): array
    {
        return [
            'from' => ! empty($input['from']) ? Carbon::parse($input['from'])->startOfDay() : now()->startOfMonth(),
            'to' => ! empty($input['to']) ? Carbon::parse($input['to'])->endOfDay() : now()->endOfMonth()->endOfDay(),
            'category_id' => ! empty($input['category_id']) ? (int) $input['category_id'] : null,
            'kind' => ($input['kind'] ?? '') ?: null,
            'paid_from' => ($input['paid_from'] ?? '') ?: null,
        ];
    }

    public function query(array $f): Builder
    {
        return Expense::query()
            ->join('expense_categories as c', 'c.id', '=', 'expenses.expense_category_id')
            ->whereBetween('expenses.date', [$f['from']->toDateString(), $f['to']->toDateString()])
            ->when($f['category_id'], fn ($q, $id) => $q->where('expenses.expense_category_id', $id))
            ->when($f['kind'], fn ($q, $kind) => $q->where('c.kind', $kind))
            ->when($f['paid_from'], fn ($q, $paid) => $q->where('expenses.paid_from', $paid));
    }

    public function list(array $f): Builder
    {
        return $this->query($f)->select('expenses.*')
            ->with(['category:id,name,name_ar,kind', 'shift:id,status'])
            ->orderByDesc('expenses.date')->orderByDesc('expenses.id');
    }

    /** @return array{total: float, fixed: float, running: float, total_lbp: float, fixed_lbp: float, running_lbp: float} USD, plus the LBP stored alongside */
    public function totals(array $f): array
    {
        $byKind = $this->query($f)->toBase()->groupBy('c.kind')
            ->selectRaw('c.kind AS kind, SUM(expenses.amount_usd) AS usd, SUM(expenses.amount_lbp) AS lbp')
            ->get()->keyBy('kind');

        $usd = fn (string $kind): float => round((float) ($byKind[$kind]->usd ?? 0), 4);
        $lbp = fn (string $kind): float => round((float) ($byKind[$kind]->lbp ?? 0), 2);

        return [
            'total' => round($usd('fixed') + $usd('running'), 4),
            'fixed' => $usd('fixed'),
            'running' => $usd('running'),
            'total_lbp' => round($lbp('fixed') + $lbp('running'), 2),
            'fixed_lbp' => $lbp('fixed'),
            'running_lbp' => $lbp('running'),
        ];
    }

    public function exportRows(array $f): array
    {
        $out = [[
            __('expenses.cols.date'), __('expenses.cols.category'), __('expenses.cols.kind'), __('expenses.cols.payee'),
            __('expenses.cols.reference'), __('expenses.cols.amount_usd'), __('expenses.cols.amount_lbp'),
            __('expenses.cols.currency'), __('expenses.cols.paid_from'), __('expenses.cols.notes'),
        ]];

        foreach ($this->list($f)->get() as $e) {
            $out[] = [
                $e->date->toDateString(), $e->category->localName(), $e->category->kindLabel(), $e->payee, $e->reference,
                (float) $e->amount_usd, (float) $e->amount_lbp, strtoupper($e->currency),
                \App\Support\EnumLabel::get('paid_from', $e->paid_from), $e->notes,
            ];
        }

        return $out;
    }

    /**
     * Last month's fixed expenses, as unsaved drafts dated the 1st of this month.
     * Nothing is written: the user reviews them and saves the ones they want.
     * A drawer expense stays a drawer expense only if the user has a shift open.
     */
    public function draftsFromLastMonth(User $user, Carbon $today): Collection
    {
        $start = $today->copy()->subMonthNoOverflow()->startOfMonth();
        $thisMonth = $today->copy()->startOfMonth()->toDateString();
        $drawerOk = (bool) $this->shifts->openFor($user);

        return Expense::query()
            ->join('expense_categories as c', 'c.id', '=', 'expenses.expense_category_id')
            ->where('c.kind', ExpenseCategory::KIND_FIXED)
            ->where('c.is_active', true)
            ->whereBetween('expenses.date', [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()])
            ->select('expenses.*')->with('category:id,name,name_ar,kind')
            ->orderBy('c.sort_order')->orderBy('expenses.id')
            ->get()
            ->map(fn (Expense $e) => [
                'expense_category_id' => $e->expense_category_id,
                'category' => $e->category->localName(),
                'payee' => $e->payee,
                'notes' => $e->notes,
                'currency' => $e->currency,
                'amount' => $e->enteredAmount(),
                'date' => $thisMonth,
                'paid_from' => $e->paid_from === Expense::PAID_DRAWER && $drawerOk ? Expense::PAID_DRAWER : Expense::PAID_OUTSIDE,
            ]);
    }

    // ---- writing -------------------------------------------------------------

    public function create(array $data, User $user): Expense
    {
        return DB::transaction(function () use ($data, $user) {
            $shift = $this->shiftFor($data['paid_from'], $user);
            [$usd, $lbp] = $this->amounts($data['currency'], (float) $data['amount']);

            $expense = Expense::create([
                'expense_category_id' => $data['expense_category_id'],
                'date' => $data['date'],
                'amount_usd' => $usd,
                'amount_lbp' => $lbp,
                'currency' => $data['currency'],
                'payee' => $data['payee'] ?? null,
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'paid_from' => $data['paid_from'],
                'shift_id' => $shift?->id,
                'created_by' => $user->id,
            ]);
            $this->syncMovement($expense, $shift, $user);

            AuditLog::record($user->id, 'create', Expense::class, $expense->id, null, $expense->only(self::AUDITED));

            return $expense;
        });
    }

    /** Create several in one go (the "duplicate last month" review screen): all or none. */
    public function createMany(array $rows, User $user): int
    {
        return DB::transaction(function () use ($rows, $user) {
            foreach ($rows as $row) {
                $this->create($row, $user);
            }

            return count($rows);
        });
    }

    public function update(Expense $expense, array $data, User $user): Expense
    {
        $this->guardUnlocked($expense);

        return DB::transaction(function () use ($expense, $data, $user) {
            $old = $expense->only(self::AUDITED);

            // A drawer expense stays on its own (still open) shift; one that moves
            // to the drawer lands on the editing user's shift.
            $keep = $expense->paid_from === Expense::PAID_DRAWER ? $expense->shift : null;
            $this->dropMovement($expense);

            $shift = $data['paid_from'] === Expense::PAID_DRAWER
                ? ($keep ?? $this->shiftFor($data['paid_from'], $user))
                : null;
            [$usd, $lbp] = $this->amounts($data['currency'], (float) $data['amount']);

            $expense->update([
                'expense_category_id' => $data['expense_category_id'],
                'date' => $data['date'],
                'amount_usd' => $usd,
                'amount_lbp' => $lbp,
                'currency' => $data['currency'],
                'payee' => $data['payee'] ?? null,
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'paid_from' => $data['paid_from'],
                'shift_id' => $shift?->id,
            ]);
            $this->syncMovement($expense, $shift, $user);

            AuditLog::record($user->id, 'update', Expense::class, $expense->id, $old, $expense->only(self::AUDITED));

            return $expense;
        });
    }

    public function delete(Expense $expense, User $user): void
    {
        $this->guardUnlocked($expense);

        DB::transaction(function () use ($expense, $user) {
            $old = $expense->only(self::AUDITED);
            $this->dropMovement($expense);
            $expense->delete(); // soft delete

            AuditLog::record($user->id, 'delete', Expense::class, $expense->id, $old, null);
        });
    }

    // ---- internals -----------------------------------------------------------

    /** [amount_usd, amount_lbp] for an amount typed in `$currency`. */
    public function amounts(string $currency, float $amount): array
    {
        return $currency === 'lbp'
            ? [$this->currency->lbpToUsd($amount), round($amount, 2)]
            : [round($amount, 4), $this->currency->usdToLbp($amount)];
    }

    private function shiftFor(string $paidFrom, User $user): ?Shift
    {
        if ($paidFrom !== Expense::PAID_DRAWER) {
            return null;
        }

        return $this->shifts->openFor($user)
            ?? throw ValidationException::withMessages(['paid_from' => __('messages.expense_needs_shift')]);
    }

    private function guardUnlocked(Expense $expense): void
    {
        if ($expense->isLocked()) {
            throw ValidationException::withMessages(['expense' => __('messages.expense_locked')]);
        }
    }

    /** Give a drawer expense its `out` cash movement on `$shift`; an outside one has none. */
    private function syncMovement(Expense $expense, ?Shift $shift, User $user): void
    {
        if (! $shift) {
            return;
        }

        $expense->loadMissing('category');
        $note = 'Expense: '.$expense->category->name.($expense->notes ? ' — '.$expense->notes : '');

        $movement = CashMovement::create([
            'shift_id' => $shift->id,
            'type' => CashMovement::TYPE_OUT,
            'currency' => $expense->currency,
            'amount_usd' => $expense->amount_usd,
            'amount_lbp' => $expense->amount_lbp,
            'note' => Str::limit($note, 250, ''),
            'created_by' => $user->id,
        ]);
        $expense->update(['cash_movement_id' => $movement->id]);
    }

    private function dropMovement(Expense $expense): void
    {
        if ($expense->cash_movement_id) {
            $movementId = $expense->cash_movement_id;
            $expense->update(['cash_movement_id' => null, 'shift_id' => null]);
            CashMovement::whereKey($movementId)->delete();
        }
    }
}
