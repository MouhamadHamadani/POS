<?php

namespace App\Http\Requests;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\BarcodeService;
use App\Services\VariantService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreProductRequest extends FormRequest
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
        $productId = $this->route('product')?->id;

        return \App\Support\PackagingRules::extend([
            'name' => 'required|string|max:255',
            'name_ar' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:5000',
            'category_id' => 'required|exists:categories,id',
            'tax_id' => 'nullable|exists:taxes,id',
            'sku' => ['nullable', 'string', 'max:80', \Illuminate\Validation\Rule::unique('products', 'sku')->ignore($productId)],
            'barcode' => ['nullable', 'string', 'max:80', \Illuminate\Validation\Rule::unique('products', 'barcode')->ignore($productId),
                // One barcode namespace: a code a variant holds is not free for a product either.
                function (string $attribute, mixed $value, \Closure $fail) use ($productId) {
                    $hit = app(BarcodeService::class)->findByBarcode((string) $value, $productId);
                    if ($hit && $hit['kind'] === BarcodeService::KIND_VARIANT) {
                        $fail(__('products.variants.err_barcode_is_variant', ['name' => $hit['variant']->displayName()]));
                    }
                }],

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

            // Variants (same price, own barcode and stock). Rows that are not mentioned are left alone;
            // a row is removed only by an explicit `remove`. Cross-row checks are in validateVariants().
            'variants' => 'nullable|array|max:200',
            'variants.*.id' => 'nullable|integer',
            'variants.*.barcode' => 'nullable|string|max:80',
            'variants.*.label' => 'nullable|string|max:80',
            'variants.*.label_ar' => 'nullable|string|max:80',
            'variants.*.sku' => 'nullable|string|max:80',
            'variants.*.stock_qty' => 'nullable|numeric|min:0',
            'variants.*.is_active' => 'nullable|boolean',
            'variants.*.remove' => 'nullable|boolean',
        ], $this);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if (! $v->errors()->hasAny(['variants', 'type']) && $this->has('variants')) {
                $this->validateVariants($v);
            } elseif ($this->route('product') && $this->input('type') !== null) {
                $this->validateTypeAgainstVariants($v, 0);
            }
        });
    }

    /**
     * What a row of variants may not be: a barcode already held by any product or variant (the whole
     * namespace), the same barcode twice in this form, or the product's own; a SKU already on another variant;
     * a row that is not this product's. A bundle or a service cannot carry variants.
     */
    private function validateVariants(Validator $v): void
    {
        $barcodes = app(BarcodeService::class);
        $productId = $this->route('product')?->id;
        $rows = VariantService::filledRows((array) $this->input('variants'));

        $mainBarcode = trim((string) $this->input('barcode'));
        $cartonBarcode = Product::packagingEnabled() ? trim((string) $this->input('carton_barcode')) : '';
        $mainSku = trim((string) $this->input('sku'));
        $seenCodes = $seenSkus = [];
        $active = 0;

        $ids = collect($rows)->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();
        $own = $ids === [] ? [] : ProductVariant::where('product_id', $productId)->whereIn('id', $ids)->pluck('id')->all();

        foreach ($rows as $i => $row) {
            $id = ! empty($row['id']) ? (int) $row['id'] : null;
            if ($id !== null && ! in_array($id, $own, true)) {
                $v->errors()->add("variants.$i.id", __('products.variants.err_not_found'));
                continue;
            }
            if (! empty($row['remove'])) {
                continue;
            }
            $active++;

            $code = trim((string) ($row['barcode'] ?? ''));
            if ($code !== '') {
                $hit = $barcodes->findByBarcode($code, $productId, $id);
                $error = match (true) {
                    $code === $mainBarcode || ($cartonBarcode !== '' && $code === $cartonBarcode) => __('products.variants.err_same_as_product'),
                    isset($seenCodes[$code]) => __('products.variants.err_duplicate_in_form'),
                    $hit !== null => __('products.taken_by', ['name' => $hit['variant']?->displayName() ?? $hit['product']->localName()]),
                    default => null,
                };
                $error ? $v->errors()->add("variants.$i.barcode", $error) : $seenCodes[$code] = true;
            }

            $sku = trim((string) ($row['sku'] ?? ''));
            if ($sku !== '') {
                $taken = $sku === $mainSku || isset($seenSkus[$sku])
                    || ProductVariant::withTrashed()->where('sku', $sku)->when($id, fn ($q) => $q->where('id', '!=', $id))->exists();
                $taken ? $v->errors()->add("variants.$i.sku", __('products.variants.err_sku_taken')) : $seenSkus[$sku] = true;
            }
        }

        $this->validateTypeAgainstVariants($v, $active, $ids);

        // Packaging builds (client/tobacco): a packaged product cannot have variants; PackagingRules refuses the reverse.
        $product = $this->route('product');
        $packaged = $this->has('has_package') ? $this->boolean('has_package') : (bool) $product?->has_package;
        if (Product::packagingEnabled() && $packaged && $active > 0) {
            $v->errors()->add('variants', __('products.variants.err_packaging'));
        }
    }

    /** $incoming = variant rows this request keeps or adds; $mentioned = existing ids it names (kept or removed). */
    private function validateTypeAgainstVariants(Validator $v, int $incoming, array $mentioned = []): void
    {
        $product = $this->route('product');
        $type = $this->input('type') ?: ($product?->type ?? Product::TYPE_SIMPLE);
        if (! in_array($type, [Product::TYPE_BUNDLE, Product::TYPE_SERVICE], true)) {
            return;
        }

        $untouched = $product ? $product->variants()->whereNotIn('id', $mentioned)->count() : 0;
        if ($incoming + $untouched > 0) {
            $v->errors()->add('type', __('products.variants.err_type'));
        }
    }

    protected function prepareForValidation(): void
    {
        $merged = [
            // Coerce checkboxes that weren't submitted to false so updates apply correctly.
            'is_active' => $this->boolean('is_active'),
            'is_taxable' => $this->boolean('is_taxable'),
            'allow_discount' => $this->boolean('allow_discount'),
            'track_stock' => $this->boolean('track_stock'),
            'force_lbp_price' => $this->boolean('force_lbp_price'),
            'remove_image' => $this->boolean('remove_image'),
        ];

        // Coerce blank-string numeric fields to their DB defaults. The browser
        // submits empty inputs as "" → ConvertEmptyStringsToNull makes them null
        // → Eloquent INSERTs NULL → NOT NULL constraint violation on columns
        // like cost_usd, stock_qty, min_stock that have `->default(0)`.
        // Only the NOT NULL columns: wholesale_price_usd, vip_price_usd, price_lbp and
        // max_stock are nullable (null tier price = retail), so a blank must reach the
        // update as NULL or an admin could never clear them.
        foreach (['cost_usd', 'stock_qty', 'min_stock'] as $numeric) {
            if ($this->input($numeric) === null && $this->has($numeric)) {
                // Field was sent blank — clear it so it's not in validated().
                $this->offsetUnset($numeric);
            }
        }

        // Same for `unit` (NOT NULL default 'pcs'): drop if blank so the model default kicks in.
        if ($this->input('unit') === null && $this->has('unit')) {
            $this->offsetUnset('unit');
        }

        $this->merge($merged + \App\Support\PackagingRules::prepare($this));
    }
}
