<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'sale_id', 'product_id', 'product_variant_id',
        'product_name', 'product_sku',
        'qty', 'unit_price_usd', 'original_unit_price_usd', 'price_tier', 'cost_usd',
        'price_overridden_by', 'price_override_approved_by', 'price_override_reason', 'price_approval_ref',
        'discount_pct', 'discount_amount_usd',
        'tax_rate', 'tax_amount_usd', 'line_total_usd',
        'is_taxable', 'is_returned', 'returned_qty', 'note',
    ];

    protected $casts = [
        'qty' => 'decimal:4',
        'unit_price_usd' => 'decimal:4',
        'original_unit_price_usd' => 'decimal:4',
        'cost_usd' => 'decimal:4',
        'discount_pct' => 'decimal:2',
        'discount_amount_usd' => 'decimal:4',
        'tax_rate' => 'decimal:4',
        'tax_amount_usd' => 'decimal:4',
        'line_total_usd' => 'decimal:4',
        'is_taxable' => 'boolean',
        'is_returned' => 'boolean',
        'returned_qty' => 'decimal:4',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** Did the cashier sell this line at a price other than the price list's? */
    public function isPriceOverridden(): bool
    {
        return $this->price_overridden_by !== null;
    }

    /** The list price a receipt prints as "was", or null: not overridden, or the store keeps it off receipts. */
    public function receiptWasPrice(): ?float
    {
        return $this->isPriceOverridden()
            && $this->original_unit_price_usd !== null
            && app(\App\Services\PriceOverrideService::class)->showOriginalOnReceipt()
            ? (float) $this->original_unit_price_usd
            : null;
    }

    public function priceOverriddenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'price_overridden_by');
    }

    public function priceOverrideApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'price_override_approved_by');
    }
}
