<?php

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Models\InventoryAdjustment;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    /**
     * Stock rule for variants (see ProductVariant): products.stock_qty is the TOTAL of the main item plus every
     * variant, product_variants.stock_qty is that variant's own count, and the main item's count is the
     * remainder (Product::mainStockQty). Every change to a variant goes through adjust() with $variant set,
     * which moves both rows in one transaction and writes one movement carrying product_variant_id.
     * qty_before / qty_after on that movement stay the PRODUCT total, so the product-level log still chains.
     *
     * Deduct stock for a sold product. Returns the resulting stock_qty.
     * Bundle products recursively deduct each component's qty.
     * Throws InsufficientStockException if a tracked product would go negative.
     */
    public function deductForSale(Product $product, float $qty, int $userId, int $saleId, ?ProductVariant $variant = null): float
    {
        return DB::transaction(function () use ($product, $qty, $userId, $saleId, $variant) {
            if ($product->type === Product::TYPE_BUNDLE) {
                $product->loadMissing('bundleComponents.component');
                foreach ($product->bundleComponents as $bi) {
                    if ($bi->component) {
                        $this->deductForSale($bi->component, $qty * (float) $bi->qty, $userId, $saleId);
                    }
                }
                return (float) $product->stock_qty;
            }

            if (!$product->track_stock) {
                return (float) $product->stock_qty;
            }

            return $this->adjust(
                $product,
                -$qty,
                InventoryAdjustment::TYPE_SALE,
                $userId,
                referenceType: 'sale',
                referenceId: $saleId,
                allowNegative: false,
                variant: $variant,
            );
        });
    }

    public function receiveStock(Product $product, float $qty, int $userId, int $poId, ?string $batch = null, ?string $expiry = null, ?string $notes = null, ?ProductVariant $variant = null): float
    {
        return $this->adjust(
            $product,
            $qty,
            InventoryAdjustment::TYPE_PO_RECEIVE,
            $userId,
            referenceType: 'purchase_order',
            referenceId: $poId,
            batchNumber: $batch,
            expiryDate: $expiry,
            notes: $notes,
            allowNegative: true,
            variant: $variant,
        );
    }

    public function restockFromReturn(Product $product, float $qty, int $userId, int $returnId, ?ProductVariant $variant = null): float
    {
        return $this->adjust(
            $product,
            $qty,
            InventoryAdjustment::TYPE_RETURN,
            $userId,
            referenceType: 'return',
            referenceId: $returnId,
            allowNegative: true,
            variant: $variant,
        );
    }

    /**
     * Initial stock entered when a product is created or imported. The product row
     * must already exist with stock_qty 0, so the log reads 0 -> qty.
     */
    public function recordOpening(Product $product, float $qty, int $userId, ?ProductVariant $variant = null): float
    {
        return $this->adjust($product, $qty, InventoryAdjustment::TYPE_OPENING, $userId, variant: $variant);
    }

    public function adjust(
        Product $product,
        float $delta,
        string $type,
        int $userId,
        ?string $reason = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $batchNumber = null,
        ?string $expiryDate = null,
        ?string $notes = null,
        bool $allowNegative = true,
        ?ProductVariant $variant = null,
    ): float {
        return DB::transaction(function () use ($product, $delta, $type, $userId, $reason, $referenceType, $referenceId, $batchNumber, $expiryDate, $notes, $allowNegative, $variant) {
            $locked = Product::where('id', $product->id)->lockForUpdate()->first();
            $lockedVariant = $variant
                ? ProductVariant::withTrashed()->where('id', $variant->id)->where('product_id', $locked->id)->lockForUpdate()->firstOrFail()
                : null;

            // $before / $after are the product total, as ever. The item that actually moves is the variant, or
            // the main item: for a product with no variants that is the same number as the total.
            $before = (float) $locked->stock_qty;
            $after = $before + $delta;
            $itemBefore = $lockedVariant
                ? (float) $lockedVariant->stock_qty
                : $before - (float) $locked->variants()->sum('stock_qty');

            // Reject oversell: tracked products may not go negative on sale deductions. A variant is checked
            // against its own count, the main item against what the variants leave of the total.
            if (!$allowNegative && $locked->track_stock && $itemBefore + $delta < 0) {
                throw new InsufficientStockException(
                    productId: $locked->id,
                    productName: $lockedVariant ? $lockedVariant->displayName(product: $locked) : $locked->name,
                    available: $itemBefore,
                    requested: abs($delta),
                );
            }

            $locked->stock_qty = $after;
            $locked->save();

            if ($lockedVariant) {
                $lockedVariant->stock_qty = (float) $lockedVariant->stock_qty + $delta;
                $lockedVariant->save();
            }

            InventoryAdjustment::create([
                'product_id' => $locked->id,
                'product_variant_id' => $lockedVariant?->id,
                'user_id' => $userId,
                'type' => $type,
                'qty_before' => $before,
                'qty_change' => $delta,
                'qty_after' => $after,
                'reason' => $reason,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'batch_number' => $batchNumber,
                'expiry_date' => $expiryDate,
                'notes' => $notes,
            ]);

            return $after;
        });
    }

    /** Set one item's count: the variant's, or the main item's (a product with no variants: its whole stock). */
    public function setStock(Product $product, float $newQty, int $userId, ?string $reason = null, ?ProductVariant $variant = null): float
    {
        $current = $variant ? (float) $variant->fresh()->stock_qty : $product->fresh()->mainStockQty();
        return $this->adjust($product, $newQty - $current, InventoryAdjustment::TYPE_SET, $userId, reason: $reason, allowNegative: true, variant: $variant);
    }
}
