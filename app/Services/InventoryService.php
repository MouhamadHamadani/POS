<?php

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Models\InventoryAdjustment;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    /**
     * Deduct stock for a sold product. Returns the resulting stock_qty.
     * Bundle products recursively deduct each component's qty.
     * Throws InsufficientStockException if a tracked product would go negative.
     */
    public function deductForSale(Product $product, float $qty, int $userId, int $saleId): float
    {
        return DB::transaction(function () use ($product, $qty, $userId, $saleId) {
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
            );
        });
    }

    public function receiveStock(Product $product, float $qty, int $userId, int $poId, ?string $batch = null, ?string $expiry = null): float
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
            allowNegative: true,
        );
    }

    public function restockFromReturn(Product $product, float $qty, int $userId, int $returnId): float
    {
        return $this->adjust(
            $product,
            $qty,
            InventoryAdjustment::TYPE_RETURN,
            $userId,
            referenceType: 'return',
            referenceId: $returnId,
            allowNegative: true,
        );
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
    ): float {
        return DB::transaction(function () use ($product, $delta, $type, $userId, $reason, $referenceType, $referenceId, $batchNumber, $expiryDate, $notes, $allowNegative) {
            $locked = Product::where('id', $product->id)->lockForUpdate()->first();
            $before = (float) $locked->stock_qty;
            $after = $before + $delta;

            // Reject oversell: tracked products may not go negative on sale deductions.
            if (!$allowNegative && $locked->track_stock && $after < 0) {
                throw new InsufficientStockException(
                    productId: $locked->id,
                    productName: $locked->name,
                    available: $before,
                    requested: abs($delta),
                );
            }

            $locked->stock_qty = $after;
            $locked->save();

            InventoryAdjustment::create([
                'product_id' => $locked->id,
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

    public function setStock(Product $product, float $newQty, int $userId, ?string $reason = null): float
    {
        $delta = $newQty - (float) $product->stock_qty;
        return $this->adjust($product, $delta, InventoryAdjustment::TYPE_SET, $userId, reason: $reason, allowNegative: true);
    }
}
