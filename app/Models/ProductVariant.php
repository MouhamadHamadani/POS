<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariant extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id', 'attributes', 'sku', 'barcode',
        'price_modifier', 'stock_qty', 'image', 'is_active',
    ];

    protected $casts = [
        'attributes' => 'array',
        'price_modifier' => 'decimal:4',
        'stock_qty' => 'decimal:4',
        'is_active' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
