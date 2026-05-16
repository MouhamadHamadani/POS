<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyTransaction extends Model
{
    use HasFactory;

    public const TYPE_EARN = 'earn';
    public const TYPE_REDEEM = 'redeem';
    public const TYPE_EXPIRE = 'expire';
    public const TYPE_ADJUST = 'adjust';

    protected $fillable = [
        'customer_id', 'sale_id', 'type',
        'points', 'balance_after', 'note',
    ];

    protected $casts = [
        'points' => 'integer',
        'balance_after' => 'integer',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
