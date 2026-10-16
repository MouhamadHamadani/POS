<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExpenseRequest;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Services\ExpenseService;
use App\Services\ShiftService;
use App\Support\Spreadsheet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExpenseController extends Controller
{
    public function __construct(
        private readonly ExpenseService $expenses,
        private readonly ShiftService $shifts,
    ) {}

    public function index(Request $request): View
    {
        $f = $this->filters($request);

        return view('expenses.index', [
            'f' => $f,
            'rows' => $this->expenses->list($f)->paginate(25)->withQueryString(),
            'totals' => $this->expenses->totals($f),
            'categories' => ExpenseCategory::orderBy('kind')->orderBy('sort_order')->get(),
        ]);
    }

    /** `demo:blocked` on the route — exports are off in a demo build. */
    public function export(Request $request): BinaryFileResponse
    {
        return Spreadsheet::downloadRows(
            $this->expenses->exportRows($this->filters($request)),
            'expenses-'.now()->format('Ymd-His'),
        );
    }

    public function create(Request $request): View
    {
        return $this->form(new Expense([
            'date' => now()->toDateString(),
            'currency' => 'usd',
            'paid_from' => Expense::PAID_OUTSIDE,
        ]), $request);
    }

    public function store(ExpenseRequest $request): RedirectResponse
    {
        $this->expenses->create($request->validated(), $request->user());

        return redirect()->route('expenses.index')->with('success', __('messages.expense_created'));
    }

    public function edit(Request $request, Expense $expense): View|RedirectResponse
    {
        $expense->load('category', 'shift');
        if ($expense->isLocked()) {
            return redirect()->route('expenses.index')->withErrors(['expense' => __('messages.expense_locked')]);
        }

        return $this->form($expense, $request);
    }

    public function update(ExpenseRequest $request, Expense $expense): RedirectResponse
    {
        $this->expenses->update($expense, $request->validated(), $request->user());

        return redirect()->route('expenses.index')->with('success', __('messages.expense_updated'));
    }

    public function destroy(Request $request, Expense $expense): RedirectResponse
    {
        $this->expenses->delete($expense, $request->user());

        return redirect()->route('expenses.index')->with('success', __('messages.expense_deleted'));
    }

    /** Last month's fixed expenses as an unsaved, editable list. */
    public function duplicateForm(Request $request): View
    {
        return view('expenses.duplicate', [
            'drafts' => $this->expenses->draftsFromLastMonth($request->user(), now()),
            'canDrawer' => (bool) $this->shifts->openFor($request->user()),
        ]);
    }

    public function duplicateStore(Request $request): RedirectResponse
    {
        $request->validate(['rows' => 'required|array|min:1'] + ExpenseRequest::rowRules(null, 'rows.*.'));

        $rows = collect($request->input('rows'))->filter(fn ($row) => ! empty($row['include']))->values()->all();
        if ($rows === []) {
            return back()->withErrors(['rows' => __('messages.expense_none_selected')]);
        }

        $count = $this->expenses->createMany($rows, $request->user());

        return redirect()->route('expenses.index')->with('success', __('messages.expenses_duplicated', ['count' => $count]));
    }

    private function form(Expense $expense, Request $request): View
    {
        $categories = ExpenseCategory::where(fn ($q) => $q->where('is_active', true)
            ->orWhere('id', $expense->expense_category_id))
            ->orderBy('sort_order')->get()->groupBy('kind');

        return view('expenses.form', [
            'expense' => $expense,
            'categories' => $categories,
            // A drawer expense being edited keeps its own (still open) shift.
            'canDrawer' => ($expense->exists && $expense->paid_from === Expense::PAID_DRAWER)
                || (bool) $this->shifts->openFor($request->user()),
        ]);
    }

    private function filters(Request $request): array
    {
        return $this->expenses->filters($request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'category_id' => 'nullable|integer',
            'kind' => 'nullable|in:fixed,running',
            'paid_from' => 'nullable|in:drawer,outside',
        ], [], ['from' => __('reports.from'), 'to' => __('reports.to')]));
    }
}
