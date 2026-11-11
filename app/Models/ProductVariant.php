<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A second barcode under a product: same price, cost, tax and category (all read from the parent), its own
 * label and its own stock count. products.stock_qty stays the TOTAL of the main item plus every variant
 * (Product::mainStockQty() is the derived remainder), so every product-level report keeps working.
 * `price_modifier` and `attributes` are legacy scaffold columns: always 0 / [] and ignored by pricing.
 */
class ProductVariant extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'product_id', 'label', 'label_ar', 'sku', 'barcode', 'stock_qty', 'is_active',
    ];

    protected $attributes = ['attributes' => '[]'];

    protected $casts = [
        'attributes' => 'array',
        'price_modifier' => 'decimal:4',
        'stock_qty' => 'decimal:4',
        'is_active' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function localLabel(): ?string
    {
        $label = app()->getLocale() === 'ar' && filled($this->label_ar) ? $this->label_ar : $this->label;

        return filled($label) ? $label : null;
    }

    /**
     * "Product — Red"; without a label "Product (…1234)" (last 4 of the barcode), so two unlabelled
     * variants never look alike in a cart. Follows the UI language; $localized = false gives the English
     * name and label, which is what a sale snapshots (sale_items.product_name is English, like a product's).
     */
    public function displayName(bool $localized = true, ?Product $product = null): string
    {
        $product ??= $this->product;

        return $this->compose(
            $localized ? ($product?->localName() ?? '') : ($product?->name ?? ''),
            $localized ? $this->localLabel() : (filled($this->label) ? $this->label : null),
        );
    }

    /** "صابون — أحمر": the Arabic names, or null when the product has no Arabic name (the English line is used). */
    public function arabicName(?Product $product = null): ?string
    {
        $product ??= $this->product;
        if (! filled($product?->name_ar)) {
            return null;
        }

        return $this->compose($product->name_ar, filled($this->label_ar) ? $this->label_ar : (filled($this->label) ? $this->label : null));
    }

    private function compose(string $name, ?string $label): string
    {
        if ($label !== null) {
            return $name . ' — ' . $label;
        }

        // A deleted variant has released its barcode into old_barcode; history still tells it apart.
        $code = (string) ($this->barcode ?? $this->old_barcode);

        return $code === '' ? $name : $name . ' (…' . substr($code, -4) . ')';
    }
}
