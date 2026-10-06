<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HeldSale extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'customer_id', 'label', 'cart', 'price_tier', 'cart_discount', 'notes',
    ];

    protected $casts = [
        'cart' => 'array',
        'cart_discount' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
