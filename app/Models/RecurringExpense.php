<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A template that books an expense on a schedule. The occurrence arithmetic and the
 * generation live in App\Services\RecurringExpenseService.
 */
class RecurringExpense extends Model
{
    use SoftDeletes;

    public const WEEKLY = 'weekly';
    public const MONTHLY = 'monthly';
    public const YEARLY = 'yearly';
    public const FREQUENCIES = [self::WEEKLY, self::MONTHLY, self::YEARLY];

    protected $fillable = [
        'expense_category_id', 'description', 'amount_usd', 'amount_lbp', 'currency', 'frequency',
        'day_of_week', 'day_of_month', 'start_date', 'end_date', 'is_active', 'last_generated_for', 'created_by',
    ];

    protected $casts = [
        'amount_usd' => 'decimal:4',
        'amount_lbp' => 'decimal:2',
        'is_active' => 'boolean',
        'day_of_week' => 'integer',
        'day_of_month' => 'integer',
    ];

    /** A date column stored as a bare Y-m-d, like Expense::date: the range filters compare it as text. */
    private static function day(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value ? Carbon::parse($value)->startOfDay() : null,
            set: fn ($value) => $value ? Carbon::parse($value)->toDateString() : null,
        );
    }

    protected function startDate(): Attribute
    {
        return self::day();
    }

    protected function endDate(): Attribute
    {
        return self::day();
    }

    protected function lastGeneratedFor(): Attribute
    {
        return self::day();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /** The amount as it was typed in, in the currency it was typed in. */
    public function enteredAmount(): float
    {
        return (float) ($this->currency === 'lbp' ? $this->amount_lbp : $this->amount_usd);
    }
}
