<?php

namespace App\Http\Controllers;

use App\Http\Requests\RecurringExpenseRequest;
use App\Models\ExpenseCategory;
use App\Models\RecurringExpense;
use App\Services\RecurringExpenseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Expenses > Recurring. Behind `role:admin,manager` and `feature:expenses` in routes/web.php,
 * the same gate as the expenses themselves.
 */
class RecurringExpenseController extends Controller
{
    public function __construct(private readonly RecurringExpenseService $recurring) {}

    public function index(): View
    {
        $templates = $this->recurring->list();

        return view('expenses.recurring.index', [
            'templates' => $templates,
            'next' => $templates->mapWithKeys(fn (RecurringExpense $t) => [$t->id => $this->recurring->nextDue($t)]),
        ]);
    }

    public function create(): View
    {
        return $this->form(new RecurringExpense([
            'currency' => 'usd', 'frequency' => RecurringExpense::MONTHLY, 'is_active' => true,
            'day_of_week' => 1, 'day_of_month' => (int) now()->day, 'start_date' => now()->toDateString(),
        ]));
    }

    public function store(RecurringExpenseRequest $request): RedirectResponse
    {
        $this->recurring->create($request->validated(), $request->user());

        return redirect()->route('expenses.recurring.index')->with('success', __('messages.recurring_created'));
    }

    public function edit(RecurringExpense $recurring): View
    {
        return $this->form($recurring);
    }

    public function update(RecurringExpenseRequest $request, RecurringExpense $recurring): RedirectResponse
    {
        $this->recurring->update($recurring, $request->validated(), $request->user());

        return redirect()->route('expenses.recurring.index')->with('success', __('messages.recurring_updated'));
    }

    /** Pause or resume. A paused template books nothing; resuming books what fell due meanwhile. */
    public function toggle(Request $request, RecurringExpense $recurring): RedirectResponse
    {
        $this->recurring->setActive($recurring, ! $recurring->is_active, $request->user());

        return back()->with('success', __($recurring->is_active ? 'messages.recurring_resumed' : 'messages.recurring_paused'));
    }

    public function destroy(Request $request, RecurringExpense $recurring): RedirectResponse
    {
        $this->recurring->delete($recurring, $request->user());

        return redirect()->route('expenses.recurring.index')->with('success', __('messages.recurring_deleted'));
    }

    public function generate(Request $request): RedirectResponse
    {
        $result = $this->recurring->generateDue(actor: $request->user(), source: RecurringExpenseService::SOURCE_MANUAL);

        return back()->with('success', $result['expenses'] === 0
            ? __('messages.recurring_none_due')
            : __('messages.recurring_generated', ['count' => $result['expenses']]));
    }

    private function form(RecurringExpense $template): View
    {
        $categories = ExpenseCategory::where(fn ($q) => $q->where('is_active', true)
            ->orWhere('id', $template->expense_category_id))
            ->orderBy('sort_order')->get()->groupBy('kind');

        return view('expenses.recurring.form', ['template' => $template, 'categories' => $categories]);
    }
}
