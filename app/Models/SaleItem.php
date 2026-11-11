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
        'sale_unit', 'unit_multiplier', 'base_qty',
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
        'unit_multiplier' => 'decimal:4',
        'base_qty' => 'decimal:4',
    ];

    /**
     * Packaging (docs/packaging.md): the unit a line was sold in, its packs-per-unit snapshot and the packs it
     * moved. `qty` stays in sale units; stock, voids and reports count `base_qty`.
     */
    protected static function booted(): void
    {
        static::creating(function (self $item) {
            // Anything that builds a line without going through SaleService (tests, imports) is a pack line.
            $item->base_qty ??= (float) $item->qty * (float) ($item->unit_multiplier ?? 1);
        });
    }

    /** Packs this line moved: what a void gives back. Falls back to qty for a row written around the model. */
    public function baseQty(): float
    {
        $base = (float) $this->base_qty;

        return $base > 0 ? $base : (float) $this->qty * (float) ($this->unit_multiplier ?: 1);
    }

    public function isPackagedUnit(): bool
    {
        return ($this->sale_unit ?? 'pack') !== 'pack';
    }

    /**
     * "Package ×10" / "Carton ×200", in the given language; '' for a pack line (a plain sale prints as it always did).
     */
    public function unitLabel(?string $locale = null): string
    {
        if (! $this->isPackagedUnit()) {
            return '';
        }

        return __('packaging.unit_x', [
            'unit' => __('packaging.unit.'.$this->sale_unit, [], $locale),
            'n' => rtrim(rtrim(number_format((float) $this->unit_multiplier, 4, '.', ''), '0'), '.'),
        ], $locale);
    }

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
