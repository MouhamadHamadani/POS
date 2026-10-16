<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ExpenseCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Settings > Expenses: the admin's list of expense categories. */
class ExpenseCategoryController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $category = ExpenseCategory::create($data + ['is_active' => true]);

        AuditLog::record($request->user()->id, 'create', ExpenseCategory::class, $category->id, null, $data);

        return $this->back(__('messages.expense_category_created', ['name' => $category->localName()]));
    }

    public function update(Request $request, ExpenseCategory $category): RedirectResponse
    {
        $data = $this->validated($request) + ['is_active' => $request->boolean('is_active')];
        $old = $category->only(array_keys($data));
        $category->update($data);

        AuditLog::record($request->user()->id, 'update', ExpenseCategory::class, $category->id, $old, $data);

        return $this->back(__('messages.expense_category_updated', ['name' => $category->localName()]));
    }

    /** Only a category nothing was ever booked to can go; otherwise deactivate it. */
    public function destroy(Request $request, ExpenseCategory $category): RedirectResponse
    {
        if ($category->expenses()->withTrashed()->exists()) {
            return back()->withErrors(['category' => __('messages.expense_category_has_expenses')]);
        }

        $category->delete();
        AuditLog::record($request->user()->id, 'delete', ExpenseCategory::class, $category->id, $category->only(['name', 'kind']), null);

        return $this->back(__('messages.expense_category_deleted', ['name' => $category->localName()]));
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'name_ar' => 'nullable|string|max:100',
            'kind' => ['required', Rule::in(ExpenseCategory::KINDS)],
            'sort_order' => 'nullable|integer|min:0|max:65535',
        ]);
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);

        return $data;
    }

    private function back(string $message): RedirectResponse
    {
        return redirect()->route('settings.index', ['tab' => 'expenses'])->with('success', $message);
    }
}
