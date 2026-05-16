<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReturnTransaction extends Model
{
    use HasFactory, HasUuid;

    protected $table = 'returns';

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'uuid', 'return_number', 'sale_id', 'user_id',
        'reason', 'reason_note', 'refund_method',
        'refund_amount_usd', 'refund_amount_lbp',
        'status', 'approved_by', 'approved_at', 'notes',
    ];

    protected $casts = [
        'refund_amount_usd' => 'decimal:4',
        'refund_amount_lbp' => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReturnItem::class, 'return_id');
    }
}
