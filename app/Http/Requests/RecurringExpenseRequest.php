<?php

namespace App\Http\Requests;

use App\Models\RecurringExpense;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecurringExpenseRequest extends FormRequest
{
    /** Whoever may manage expenses may manage their templates. */
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasRole(User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN, User::ROLE_MANAGER);
    }

    public function rules(): array
    {
        $category = ExpenseRequest::rowRules($this->route('recurring')?->expense_category_id)['expense_category_id'];

        return [
            'expense_category_id' => $category,
            'description' => 'nullable|string|max:255',
            'currency' => 'required|in:usd,lbp',
            'amount' => 'required|numeric|gt:0|max:999999999999',
            'frequency' => ['required', Rule::in(RecurringExpense::FREQUENCIES)],
            'day_of_week' => ['required_if:frequency,'.RecurringExpense::WEEKLY, 'nullable', 'integer', 'between:1,7'],
            'day_of_month' => ['required_if:frequency,'.RecurringExpense::MONTHLY, 'nullable', 'integer', 'between:1,31'],
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'is_active' => 'nullable|boolean',
        ];
    }
}
