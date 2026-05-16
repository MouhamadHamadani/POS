<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Discount extends Model
{
    use HasFactory, HasUuid;

    public const TYPE_PERCENT = 'percent';
    public const TYPE_FIXED = 'fixed';
    public const TYPE_BOGO = 'bogo';
    public const TYPE_BUNDLE = 'bundle';
    public const TYPE_COUPON = 'coupon';
    public const TYPE_LOYALTY = 'loyalty';

    protected $fillable = [
        'uuid', 'name', 'type', 'value',
        'min_cart_amount', 'max_discount_amount',
        'valid_from', 'valid_to',
        'max_uses', 'uses_count', 'max_per_customer',
        'product_ids', 'category_ids', 'coupon_code',
        'is_combinable', 'is_automatic', 'is_active',
    ];

    protected $casts = [
        'value' => 'decimal:4',
        'min_cart_amount' => 'decimal:4',
        'max_discount_amount' => 'decimal:4',
        'valid_from' => 'datetime',
        'valid_to' => 'datetime',
        'product_ids' => 'array',
        'category_ids' => 'array',
        'is_combinable' => 'boolean',
        'is_automatic' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function isValid(?float $cartTotal = null): bool
    {
        if (!$this->is_active) {
            return false;
        }
        $now = now();
        if ($this->valid_from && $now->lt($this->valid_from)) {
            return false;
        }
        if ($this->valid_to && $now->gt($this->valid_to)) {
            return false;
        }
        if ($this->max_uses !== null && $this->uses_count >= $this->max_uses) {
            return false;
        }
        if ($cartTotal !== null && $this->min_cart_amount && $cartTotal < $this->min_cart_amount) {
            return false;
        }
        return true;
    }
}
