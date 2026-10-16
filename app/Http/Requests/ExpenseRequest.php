<?php

namespace App\Http\Requests;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class ExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasRole(User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN, User::ROLE_MANAGER);
    }

    public function rules(): array
    {
        return self::rowRules($this->route('expense')?->expense_category_id);
    }

    /**
     * Shared by the form and the "duplicate last month" rows. A category that has
     * been deactivated since can still be kept on an expense that already uses it,
     * but not picked for a new one.
     */
    public static function rowRules(?int $currentCategoryId = null, string $prefix = ''): array
    {
        return [
            $prefix.'expense_category_id' => ['bail', 'required', 'integer', function ($attribute, $value, $fail) use ($currentCategoryId) {
                $category = ExpenseCategory::find($value);
                if (! $category || (! $category->is_active && (int) $value !== $currentCategoryId)) {
                    $fail(__('messages.expense_category_invalid'));
                }
            }],
            $prefix.'date' => 'required|date',
            $prefix.'currency' => 'required|in:usd,lbp',
            $prefix.'amount' => 'required|numeric|gt:0|max:999999999999',
            $prefix.'payee' => 'nullable|string|max:150',
            $prefix.'reference' => 'nullable|string|max:100',
            $prefix.'notes' => 'nullable|string|max:1000',
            $prefix.'paid_from' => 'required|in:'.Expense::PAID_DRAWER.','.Expense::PAID_OUTSIDE,
        ];
    }
}
