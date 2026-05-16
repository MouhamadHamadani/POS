<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shift extends Model
{
    use HasFactory, HasUuid;

    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'uuid', 'user_id', 'opened_at', 'closed_at',
        'opening_cash_usd', 'opening_cash_lbp',
        'closing_cash_usd', 'closing_cash_lbp',
        'expected_cash_usd', 'expected_cash_lbp',
        'variance_usd', 'variance_lbp',
        'status', 'closed_by', 'notes',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'opening_cash_usd' => 'decimal:4',
        'opening_cash_lbp' => 'decimal:2',
        'closing_cash_usd' => 'decimal:4',
        'closing_cash_lbp' => 'decimal:2',
        'expected_cash_usd' => 'decimal:4',
        'expected_cash_lbp' => 'decimal:2',
        'variance_usd' => 'decimal:4',
        'variance_lbp' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function cashMovements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }
}
