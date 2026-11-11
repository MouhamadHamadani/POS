<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A repayment a customer made against their debt (or a write-off of it, `kind = write_off`). The header only:
 * which receipts it was applied to is in the ledger entries that point at it. Debt is kept in USD, so a payment
 * in LBP stores the LBP amount, the rate used and the USD it came to.
 */
class CustomerPayment extends Model
{
    public const KIND_PAYMENT = 'payment';
    public const KIND_WRITE_OFF = 'write_off';

    public const METHOD_CASH = 'cash';
    public const METHOD_CARD = 'card';
    public const METHOD_WISH = 'wish';
    public const METHOD_BANK = 'bank_transfer';
    public const METHOD_OTHER = 'other';

    public const METHODS = [self::METHOD_CASH, self::METHOD_CARD, self::METHOD_WISH, self::METHOD_BANK, self::METHOD_OTHER];

    protected $fillable = [
        'number', 'customer_id', 'user_id', 'shift_id', 'kind', 'method', 'currency',
        'amount', 'exchange_rate', 'amount_usd', 'paid_at', 'reference', 'notes',
        'voided_at', 'voided_by', 'void_reason',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'exchange_rate' => 'decimal:6',
        'amount_usd' => 'decimal:4',
        'paid_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(CustomerLedgerEntry::class, 'customer_payment_id');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    public function isWriteOff(): bool
    {
        return $this->kind === self::KIND_WRITE_OFF;
    }

    /** Cash that went into a drawer: a cash repayment that was not voided. */
    public function scopeDrawerCash($query)
    {
        return $query->where('kind', self::KIND_PAYMENT)->where('method', self::METHOD_CASH)->whereNull('voided_at');
    }

    public function methodLabel(): string
    {
        return \App\Support\EnumLabel::get('debt_method', $this->isWriteOff() ? 'write_off' : $this->method);
    }
}
