<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Validation\ValidationException;

/**
 * Create, edit and remove a product's variants from the product form's rows. Stock rules live in
 * InventoryService (a new variant's opening stock goes through it); price, cost and tax are never stored on a
 * variant: they are the parent product's.
 *
 * Row shape (the form posts these under `variants`):
 *   id? (existing variant), barcode, label, label_ar, sku, stock_qty (opening stock, new rows only),
 *   is_active, remove.
 */
class VariantService
{
    public function __construct(
        private readonly BarcodeService $barcodes,
        private readonly InventoryService $inventory,
    ) {}

    /** Rows the user actually filled in: the empty trailing row a scanner-driven form leaves is dropped. */
    public static function filledRows(array $rows): array
    {
        return array_filter($rows, function ($row) {
            if (! is_array($row)) {
                return false;
            }
            if (! empty($row['id']) || ! empty($row['remove'])) {
                return true;
            }

            return filled($row['barcode'] ?? null) || filled($row['label'] ?? null) || filled($row['label_ar'] ?? null)
                || filled($row['sku'] ?? null) || (float) ($row['stock_qty'] ?? 0) != 0.0;
        });
    }

    /** Apply the (already validated) rows to the product. Call inside the caller's transaction. */
    public function sync(Product $product, array $rows, int $userId): void
    {
        foreach (self::filledRows($rows) as $row) {
            $existing = ! empty($row['id']) ? $product->variants()->find($row['id']) : null;

            if (! empty($row['remove'])) {
                if ($existing) {
                    $this->delete($existing, $userId);
                }
                continue;
            }

            $attrs = [
                'label' => $this->text($row['label'] ?? null),
                'label_ar' => $this->text($row['label_ar'] ?? null),
                'sku' => $this->text($row['sku'] ?? null),
                'is_active' => ! array_key_exists('is_active', $row) || filter_var($row['is_active'], FILTER_VALIDATE_BOOLEAN),
            ];
            $barcode = $this->text($row['barcode'] ?? null);

            if ($existing) {
                // A blank barcode on an existing row keeps the one it has: it is never cleared by accident.
                if ($barcode !== null) {
                    $attrs['barcode'] = $barcode;
                }
                $old = $existing->only(array_keys($attrs));
                $existing->update($attrs);

                $changed = array_diff_assoc($existing->only(array_keys($attrs)), $old);
                if ($changed !== []) {
                    $action = match (true) {
                        array_key_exists('is_active', $changed) && ! $existing->is_active => 'variant_deactivate',
                        array_key_exists('is_active', $changed) && $existing->is_active && count($changed) === 1 => 'variant_activate',
                        default => 'variant_update',
                    };
                    AuditLog::record($userId, $action, ProductVariant::class, $existing->id, array_intersect_key($old, $changed), $changed);
                }
                continue;
            }

            $variant = $product->variants()->create($attrs + [
                'barcode' => $barcode ?? $this->barcodes->generateUniqueEan13(),
                'stock_qty' => 0,
            ]);

            $opening = (float) ($row['stock_qty'] ?? 0);
            if ($opening != 0.0) {
                $this->inventory->recordOpening($product, $opening, $userId, $variant);
            }

            AuditLog::record($userId, 'variant_create', ProductVariant::class, $variant->id, null, [
                'product_id' => $product->id,
                'barcode' => $variant->barcode,
                'label' => $variant->label,
                'sku' => $variant->sku,
                'stock_qty' => $opening,
            ]);
        }
    }

    /**
     * Remove a variant. Refused while it holds stock (the count would vanish from the product total with no
     * movement); otherwise it is soft-deleted, never hard-deleted, because past sales point at it, and its
     * barcode / SKU are released for reuse (kept in old_barcode / old_sku).
     */
    public function delete(ProductVariant $variant, int $userId): void
    {
        if (abs((float) $variant->stock_qty) > 0.00005) {
            throw ValidationException::withMessages([
                'variants' => __('products.variants.err_delete_stock', ['name' => $variant->displayName()]),
            ]);
        }

        $this->trash($variant, $userId);
    }

    /** A deleted product takes its variants with it, whatever they hold: the codes are freed like the product's. */
    public function releaseAllFor(Product $product, int $userId): void
    {
        foreach ($product->variants()->get() as $variant) {
            $this->trash($variant, $userId);
        }
    }

    private function trash(ProductVariant $variant, int $userId): void
    {
        $old = ['product_id' => $variant->product_id, 'barcode' => $variant->barcode, 'sku' => $variant->sku, 'label' => $variant->label];

        $variant->old_barcode = $variant->barcode;
        $variant->old_sku = $variant->sku;
        $variant->barcode = null;
        $variant->sku = null;
        $variant->save();
        $variant->delete();

        AuditLog::record($userId, 'variant_delete', ProductVariant::class, $variant->id, $old, null);
    }

    private function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
