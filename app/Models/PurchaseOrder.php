<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    use HasFactory, HasUuid;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENT = 'sent';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_RECEIVED = 'received';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'uuid', 'po_number', 'supplier_id', 'user_id', 'status',
        'subtotal_usd', 'tax_amount_usd', 'shipping_usd', 'total_usd',
        'expected_at', 'received_at', 'supplier_reference', 'notes',
    ];

    protected $casts = [
        'expected_at' => 'date',
        'received_at' => 'datetime',
        'subtotal_usd' => 'decimal:4',
        'tax_amount_usd' => 'decimal:4',
        'shipping_usd' => 'decimal:4',
        'total_usd' => 'decimal:4',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }
}
