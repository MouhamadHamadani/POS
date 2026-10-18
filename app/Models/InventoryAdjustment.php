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
    public const TYPE_OPENING = 'opening';

    /** Every type the movements screen can filter by, in display order. */
    public const TYPES = [
        self::TYPE_SALE, self::TYPE_RETURN, self::TYPE_PO_RECEIVE, self::TYPE_OPENING,
        self::TYPE_ADD, self::TYPE_REMOVE, self::TYPE_SET, self::TYPE_COUNT,
    ];

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

    /** Free-text reasons print as typed; the few the system writes itself are translated. */
    public function reasonLabel(): ?string
    {
        if ($this->reason === null || $this->reason === '') {
            return null;
        }

        $key = "enums.stock_reason.{$this->reason}";

        return \Illuminate\Support\Facades\Lang::has($key) ? __($key) : $this->reason;
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
