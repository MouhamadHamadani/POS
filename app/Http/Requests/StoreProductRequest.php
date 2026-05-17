<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, ['admin', 'manager', 'stock'], true);
    }

    public function rules(): array
    {
        $productId = $this->route('product')?->id;

        return [
            'name' => 'required|string|max:255',
            'name_ar' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:5000',
            'category_id' => 'required|exists:categories,id',
            'tax_id' => 'nullable|exists:taxes,id',
            'sku' => ['nullable', 'string', 'max:80', \Illuminate\Validation\Rule::unique('products', 'sku')->ignore($productId)],
            'barcode' => ['nullable', 'string', 'max:80', \Illuminate\Validation\Rule::unique('products', 'barcode')->ignore($productId)],

            'price_usd' => 'required|numeric|min:0',
            'cost_usd' => 'nullable|numeric|min:0',
            'wholesale_price_usd' => 'nullable|numeric|min:0',
            'vip_price_usd' => 'nullable|numeric|min:0',
            'price_lbp' => 'nullable|numeric|min:0',
            'force_lbp_price' => 'nullable|boolean',

            'stock_qty' => 'nullable|numeric|min:0',
            'min_stock' => 'nullable|numeric|min:0',
            'max_stock' => 'nullable|numeric|min:0',
            'unit' => 'nullable|string|max:20',
            'location' => 'nullable|string|max:80',

            'type' => 'nullable|in:simple,variant,bundle,service',
            'is_active' => 'nullable|boolean',
            'is_taxable' => 'nullable|boolean',
            'allow_discount' => 'nullable|boolean',
            'track_stock' => 'nullable|boolean',

            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp,gif|max:4096',
            'remove_image' => 'nullable|boolean',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Coerce checkboxes that weren't submitted to false so updates apply correctly.
        $this->merge([
            'is_active' => $this->boolean('is_active'),
            'is_taxable' => $this->boolean('is_taxable'),
            'allow_discount' => $this->boolean('allow_discount'),
            'track_stock' => $this->boolean('track_stock'),
            'force_lbp_price' => $this->boolean('force_lbp_price'),
            'remove_image' => $this->boolean('remove_image'),
        ]);
    }
}
