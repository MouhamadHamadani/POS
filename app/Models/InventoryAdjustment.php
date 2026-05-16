<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryAdjustment extends Model
{
    use HasFactory;

    public const TYPE_ADD = 'add';
    public const TYPE_REMOVE = 'remove';
    public const TYPE_SET = 'set';
    public const TYPE_COUNT = 'count';
    public const TYPE_SALE = 'sale';
    public const TYPE_RETURN = 'return';
    public const TYPE_PO_RECEIVE = 'po_receive';

    protected $fillable = [
        'product_id', 'user_id', 'type',
        'qty_before', 'qty_change', 'qty_after',
        'reason', 'reference_type', 'reference_id',
        'batch_number', 'expiry_date', 'notes',
    ];

    protected $casts = [
        'qty_before' => 'decimal:4',
        'qty_change' => 'decimal:4',
        'qty_after' => 'decimal:4',
        'expiry_date' => 'date',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
