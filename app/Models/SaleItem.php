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
        'qty', 'unit_price_usd', 'cost_usd',
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
}
