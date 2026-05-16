<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    public const TYPE_SIMPLE = 'simple';
    public const TYPE_VARIANT = 'variant';
    public const TYPE_BUNDLE = 'bundle';
    public const TYPE_SERVICE = 'service';

    protected $fillable = [
        'uuid', 'category_id', 'tax_id', 'barcode', 'sku',
        'name', 'name_ar', 'description', 'image',
        'price_usd', 'cost_usd', 'wholesale_price_usd', 'vip_price_usd',
        'price_lbp', 'cost_lbp',
        'stock_qty', 'min_stock', 'max_stock', 'unit', 'location',
        'type', 'is_active', 'is_taxable', 'allow_discount',
        'track_stock', 'force_lbp_price', 'created_by',
    ];

    protected $casts = [
        'price_usd' => 'decimal:4',
        'cost_usd' => 'decimal:4',
        'wholesale_price_usd' => 'decimal:4',
        'vip_price_usd' => 'decimal:4',
        'price_lbp' => 'decimal:2',
        'cost_lbp' => 'decimal:2',
        'stock_qty' => 'decimal:4',
        'min_stock' => 'decimal:4',
        'max_stock' => 'decimal:4',
        'is_active' => 'boolean',
        'is_taxable' => 'boolean',
        'allow_discount' => 'boolean',
        'track_stock' => 'boolean',
        'force_lbp_price' => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function bundleComponents(): HasMany
    {
        return $this->hasMany(BundleItem::class, 'bundle_product_id');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(InventoryAdjustment::class);
    }

    public function getLocalNameAttribute(): string
    {
        return app()->getLocale() === 'ar' && $this->name_ar ? $this->name_ar : $this->name;
    }

    public function localName(): string
    {
        return $this->getLocalNameAttribute();
    }

    public function priceForGroup(string $group): float
    {
        return match ($group) {
            'wholesale' => (float) ($this->wholesale_price_usd ?? $this->price_usd),
            'vip' => (float) ($this->vip_price_usd ?? $this->price_usd),
            default => (float) $this->price_usd,
        };
    }

    public function isLowStock(): bool
    {
        return $this->track_stock && $this->stock_qty <= $this->min_stock;
    }
}
