<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** The PO form's "+ New product" modal: just enough to order it. The sell price comes later. */
class QuickStoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasRole(
            \App\Models\User::ROLE_SUPER_ADMIN,
            \App\Models\User::ROLE_ADMIN,
            \App\Models\User::ROLE_MANAGER,
            \App\Models\User::ROLE_STOCK,
        );
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'name_ar' => 'nullable|string|max:255',
            'category_id' => 'required|exists:categories,id',
            'tax_id' => 'nullable|exists:taxes,id',
            'unit' => 'nullable|string|max:20',
            'cost_usd' => 'nullable|numeric|min:0',
        ];
    }
}
