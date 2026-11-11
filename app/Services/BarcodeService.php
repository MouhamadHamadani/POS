<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;

class BarcodeService
{
    /**
     * Generate a unique EAN-13 barcode. The 13th digit is the check digit;
     * the first 12 digits are derived from a uniqid + product id seed.
     */
    public function generateUniqueEan13(): string
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $base12 = str_pad((string) random_int(100_000_000_000, 999_999_999_999), 12, '0', STR_PAD_LEFT);
            $candidate = $base12 . $this->ean13CheckDigit($base12);

            if ($this->findByBarcode($candidate) === null) {
                return $candidate;
            }
        }
        throw new \RuntimeException('Could not generate a unique barcode after 20 attempts.');
    }

    public const KIND_PRODUCT = 'product';
    public const KIND_CARTON = 'carton'; // packaging builds only (client/tobacco)
    public const KIND_VARIANT = 'variant';

    /**
     * Who holds a barcode. One namespace across products.barcode and product_variants.barcode:
     *   ['product' => Product, 'variant' => ?ProductVariant, 'kind' => 'product'|'carton'|'variant'], or null.
     * A scanned variant code resolves to its parent product plus the variant.
     *
     * withTrashed(): the DB unique indexes and the form's unique rules still see soft-deleted rows, so every
     * duplicate check must too, or the preview says "free" and the INSERT says otherwise. Deleting a product
     * or a variant nulls its barcode/sku, so a trashed row should no longer hold a code at all; this stays as
     * the guard that keeps the preview honest if one ever does.
     *
     * $exceptProductId / $exceptVariantId leave out the row being edited.
     */
    public function findByBarcode(string $barcode, ?int $exceptProductId = null, ?int $exceptVariantId = null): ?array
    {
        $code = trim($barcode);
        if ($code === '') {
            return null;
        }

        $product = Product::withTrashed()
            ->where('barcode', $code)
            ->when($exceptProductId, fn ($q) => $q->where('id', '!=', $exceptProductId))
            ->first();
        if ($product) {
            return ['product' => $product, 'variant' => null, 'kind' => self::KIND_PRODUCT];
        }

        // A carton barcode is one more code a product holds, but only on a packaging build: with the flag off this
        // branch answers exactly as main does.
        if (Product::packagingEnabled()) {
            $product = Product::withTrashed()
                ->where('carton_barcode', $code)
                ->when($exceptProductId, fn ($q) => $q->where('id', '!=', $exceptProductId))
                ->first();
            if ($product) {
                return ['product' => $product, 'variant' => null, 'kind' => self::KIND_CARTON];
            }
        }

        $variant = ProductVariant::withTrashed()
            ->with(['product' => fn ($q) => $q->withTrashed()])
            ->where('barcode', $code)
            ->when($exceptVariantId, fn ($q) => $q->where('id', '!=', $exceptVariantId))
            ->first();

        return $variant ? ['product' => $variant->product, 'variant' => $variant, 'kind' => self::KIND_VARIANT] : null;
    }

    public function ean13CheckDigit(string $first12): string
    {
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $first12[$i] * (($i % 2) === 0 ? 1 : 3);
        }
        $check = (10 - ($sum % 10)) % 10;
        return (string) $check;
    }

    /**
     * Render a Code128 / EAN-13 barcode as inline SVG (works in HTML + PDF).
     */
    public function svg(string $code, string $type = 'C128'): string
    {
        $generator = new \Picqer\Barcode\BarcodeGeneratorSVG();
        $map = [
            'C128' => $generator::TYPE_CODE_128,
            'EAN13' => $generator::TYPE_EAN_13,
            'C39' => $generator::TYPE_CODE_39,
        ];
        return $generator->getBarcode($code, $map[$type] ?? $generator::TYPE_CODE_128);
    }
}
