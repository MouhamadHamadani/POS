<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Validation\ValidationException;

/**
 * Receiving stock by the package or the carton (client/tobacco, docs/packaging.md).
 *
 * Stock is only ever counted in packs, so a quantity typed in another unit is converted here, on the server,
 * before it reaches InventoryService. Selling is not in this class: SaleService asks the product directly
 * (Product::supportsUnit / priceForUnit / multiplierFor).
 */
class PackagingService
{
    /**
     * Packs in $qty of $unit. A unit the product is not set up with (or any non-pack unit while the packaging
     * flag is off) is a validation error on the posted `unit` field, never a silent fall back to packs.
     */
    public function toPacks(Product $product, ?string $unit, float $qty, string $field = 'unit'): float
    {
        $unit = $unit ?: Product::UNIT_PACK;

        if (! in_array($unit, $product->receivableUnits(), true)) {
            throw ValidationException::withMessages([
                $field => __('packaging.errors.receive_unit_not_available', ['product' => $product->localName(), 'unit' => __('packaging.unit.'.$unit)]),
            ]);
        }

        return round($qty * $product->multiplierFor($unit), 4);
    }

    /** Per-pack cost from a cost typed per $unit (carton cost / packs per carton). */
    public function costPerPack(Product $product, ?string $unit, float $cost): float
    {
        $packs = $this->toPacks($product, $unit, 1.0);

        return $packs > 0 ? round($cost / $packs, 4) : $cost;
    }

    /**
     * Purchase-order receipts as the rest of PurchaseOrderController::receive() understands them: qty in packs and
     * cost_usd per pack. A receipt without a `unit` (or with `pack`) is returned untouched. The typed quantity and
     * unit stay on the row (`unit`, `typed_qty`) so the audit entry says what was received.
     *
     * @param  array<int, array<string, mixed>>  $receipts
     * @return array<int, array<string, mixed>>
     */
    public function receiptsInPacks(array $receipts, \App\Models\PurchaseOrder $po): array
    {
        foreach ($receipts as $idx => $r) {
            $unit = $r['unit'] ?? Product::UNIT_PACK;
            $typed = (float) ($r['qty'] ?? 0);
            if ($unit === Product::UNIT_PACK || $typed <= 0) {
                continue;
            }

            $item = $po->items()->whereKey($r['item_id'])->first();
            $product = $item ? Product::find($item->product_id) : null;
            if (! $product) {
                continue; // an item that is not on this order: receive() refuses it the way it always did
            }

            $receipts[$idx]['qty'] = $this->toPacks($product, $unit, $typed, "receipts.$idx.unit");
            $receipts[$idx]['typed_qty'] = $typed;
            // The cost override is typed per received unit; the product's cost_usd is per pack.
            if (! empty($r['cost_usd'])) {
                $receipts[$idx]['cost_usd'] = $this->costPerPack($product, $unit, (float) $r['cost_usd']);
            }
        }

        return $receipts;
    }

    /** "3 × Carton = 600 packs" for the movement log; null for a plain pack quantity. */
    public function receiptNote(Product $product, ?string $unit, float $qty, float $packs): ?string
    {
        if (! $unit || $unit === Product::UNIT_PACK) {
            return null;
        }

        return __('packaging.receive_note', [
            'qty' => rtrim(rtrim(number_format($qty, 4, '.', ''), '0'), '.'),
            'unit' => __('packaging.unit.'.$unit),
            'packs' => rtrim(rtrim(number_format($packs, 4, '.', ''), '0'), '.'),
        ]);
    }
}
