<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Expense extends Model
{
    use SoftDeletes;

    public const PAID_DRAWER = 'drawer';
    public const PAID_OUTSIDE = 'outside';

    protected $fillable = [
        'expense_category_id', 'date', 'amount_usd', 'amount_lbp', 'currency',
        'payee', 'reference', 'notes', 'paid_from', 'shift_id', 'cash_movement_id', 'created_by',
    ];

    protected $casts = [
        'amount_usd' => 'decimal:4',
        'amount_lbp' => 'decimal:2',
    ];

    /**
     * Always stored as a bare Y-m-d. Eloquent's own `date` cast would write a full
     * datetime string, and the range filters and month grouping compare this
     * column as text.
     */
    protected function date(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value ? Carbon::parse($value)->startOfDay() : null,
            set: fn ($value) => Carbon::parse($value)->toDateString(),
        );
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function cashMovement(): BelongsTo
    {
        return $this->belongsTo(CashMovement::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * A drawer expense is part of a shift's cash count. Once that shift is closed
     * its expected/variance figures are final, so the expense can no longer change.
     */
    public function isLocked(): bool
    {
        return $this->paid_from === self::PAID_DRAWER && ! $this->shift?->isOpen();
    }

    /** The amount as it was typed in, in the currency it was typed in. */
    public function enteredAmount(): float
    {
        return (float) ($this->currency === 'lbp' ? $this->amount_lbp : $this->amount_usd);
    }
}
