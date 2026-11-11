<?php

namespace App\Support;

use App\Models\Product;
use App\Services\BarcodeService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for the Packaging section of the product form (client/tobacco, docs/packaging.md).
 *
 * StoreProductRequest calls extend() on its rules and prepare() on its inputs; with POS_PACKAGING off both
 * return their argument untouched, so main's request is exactly what it was and posted packaging fields are
 * simply not part of validated().
 */
class PackagingRules
{
    private const PACKAGE_COLUMNS = ['package_size', 'package_price_usd', 'package_wholesale_price_usd', 'package_vip_price_usd'];
    private const CARTON_COLUMNS = ['packages_per_carton', 'carton_price_usd', 'carton_wholesale_price_usd', 'carton_vip_price_usd'];

    /** Checkboxes become real booleans, and a section that is switched off stores nothing (no stale prices behind a hidden panel). */
    public static function prepare(FormRequest $request): array
    {
        if (! Product::packagingEnabled()) {
            return [];
        }

        $hasPackage = $request->boolean('has_package');
        $carton = trim((string) $request->input('carton_barcode')) ?: null;

        $out = [
            'has_package' => $hasPackage,
            'carton_barcode' => $carton,
            'sell_carton_at_pos' => $carton !== null && $request->boolean('sell_carton_at_pos'),
        ];
        foreach (self::PACKAGE_COLUMNS as $col) {
            $out[$col] = $hasPackage ? $request->input($col) : null;
        }
        foreach (self::CARTON_COLUMNS as $col) {
            $out[$col] = $carton !== null ? $request->input($col) : null;
        }
        // Cartons are only sold when the switch is on; a retail price is then required (see rules()).
        if (! $out['sell_carton_at_pos']) {
            foreach (['carton_price_usd', 'carton_wholesale_price_usd', 'carton_vip_price_usd'] as $col) {
                $out[$col] = null;
            }
        }

        return $out;
    }

    public static function extend(array $rules, FormRequest $request): array
    {
        if (! Product::packagingEnabled()) {
            return $rules;
        }

        $product = $request->route('product');
        $productId = $product?->id;
        $barcodes = app(BarcodeService::class);
        $ownBarcode = fn () => trim((string) $request->input('barcode'));

        // A pack barcode may not be any product's carton barcode (a scan would be ambiguous), and not its own either.
        $rules['barcode'][] = function (string $attr, mixed $value, \Closure $fail) use ($barcodes, $productId, $request) {
            $hit = $barcodes->findByBarcode((string) $value, $productId);
            if ($hit && $hit['kind'] === BarcodeService::KIND_CARTON) {
                $fail(__('packaging.errors.barcode_taken', ['name' => $hit['product']->name]));
            }
            if (trim((string) $value) !== '' && trim((string) $value) === trim((string) $request->input('carton_barcode'))) {
                $fail(__('packaging.errors.carton_equals_pack'));
            }
        };

        return $rules + [
            'has_package' => ['boolean', function (string $attr, mixed $value, \Closure $fail) use ($request, $product) {
                if (! $value) {
                    return;
                }
                $type = $request->input('type') ?: ($product?->type ?? Product::TYPE_SIMPLE);
                if ($type !== Product::TYPE_SIMPLE || ($product && $product->variants()->exists())) {
                    $fail(__('packaging.errors.simple_only'));
                }
            }],
            'package_size' => ['nullable', 'integer', 'min:2', 'max:1000', 'required_if:has_package,true'],
            'package_price_usd' => ['nullable', 'numeric', 'min:0', 'required_if:has_package,true'],
            'package_wholesale_price_usd' => 'nullable|numeric|min:0',
            'package_vip_price_usd' => 'nullable|numeric|min:0',

            'carton_barcode' => ['nullable', 'string', 'max:80', function (string $attr, mixed $value, \Closure $fail) use ($barcodes, $productId, $request, $ownBarcode) {
                if (! $request->boolean('has_package')) {
                    $fail(__('packaging.errors.carton_needs_package'));
                }
                if (trim((string) $value) === $ownBarcode()) {
                    $fail(__('packaging.errors.carton_equals_pack'));
                }
                if ($hit = $barcodes->findByBarcode((string) $value, $productId)) {
                    $fail(__('packaging.errors.barcode_taken', ['name' => $hit['variant']?->displayName() ?? $hit['product']->name]));
                }
            }],
            'packages_per_carton' => ['nullable', 'integer', 'min:1', 'max:1000', 'required_with:carton_barcode'],
            'sell_carton_at_pos' => ['boolean'],
            'carton_price_usd' => ['nullable', 'numeric', 'min:0', 'required_if:sell_carton_at_pos,true'],
            'carton_wholesale_price_usd' => 'nullable|numeric|min:0',
            'carton_vip_price_usd' => 'nullable|numeric|min:0',
        ];
    }
}
