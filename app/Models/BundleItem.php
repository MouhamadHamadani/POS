<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BundleItem extends Model
{
    use HasFactory;

    protected $fillable = ['bundle_product_id', 'component_id', 'qty'];

    protected $casts = ['qty' => 'decimal:4'];

    /** A product with variants cannot be a bundle component (the bundle would not know which item to take). */
    protected static function booted(): void
    {
        static::saving(function (BundleItem $item) {
            if (Product::find($item->component_id)?->hasVariants()) {
                throw \Illuminate\Validation\ValidationException::withMessages(['component_id' => __('products.variants.err_bundle_component')]);
            }
        });
    }

    public function bundle(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'bundle_product_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'component_id');
    }
}
