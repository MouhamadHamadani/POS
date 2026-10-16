<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaleItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'sale_id', 'product_id', 'product_variant_id',
        'product_name', 'product_sku',
        'qty', 'unit_price_usd', 'price_tier', 'cost_usd',
        'discount_pct', 'discount_amount_usd',
        'tax_rate', 'tax_amount_usd', 'line_total_usd',
        'is_taxable', 'is_returned', 'returned_qty', 'note',
    ];

    protected $casts = [
        'qty' => 'decimal:4',
        'unit_price_usd' => 'decimal:4',
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

    public function returnItems(): HasMany
    {
        return $this->hasMany(ReturnItem::class);
    }

    /** Units on returns still waiting for a manager. They are not in returned_qty until approved. */
    public function pendingReturnQty(): float
    {
        return (float) $this->returnItems()
            ->whereHas('returnTransaction', fn ($q) => $q->where('status', ReturnTransaction::STATUS_PENDING))
            ->sum('qty_returned');
    }

    /** Units that can still go on a new return: sold, less returned, less tied up in pending returns. */
    public function returnableQty(): float
    {
        return max(0.0, (float) $this->qty - (float) $this->returned_qty - $this->pendingReturnQty());
    }
}
