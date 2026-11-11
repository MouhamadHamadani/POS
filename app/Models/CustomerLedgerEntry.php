<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a customer's debt book. Append-only: nothing updates or deletes a row (a mistake is put right
 * with a reversing row), and only App\Services\CustomerLedgerService creates them. `amount_usd` is signed:
 * + raises what the customer owes, - lowers it.
 */
class CustomerLedgerEntry extends Model
{
    public const TYPE_OPENING = 'opening';
    public const TYPE_CREDIT_SALE = 'credit_sale';
    public const TYPE_PAYMENT = 'payment';
    public const TYPE_WRITE_OFF = 'write_off';
    public const TYPE_RETURN_CREDIT = 'return_credit';
    public const TYPE_VOID_REVERSAL = 'void_reversal';
    public const TYPE_ADJUSTMENT = 'adjustment';

    public const TYPES = [
        self::TYPE_OPENING, self::TYPE_CREDIT_SALE, self::TYPE_PAYMENT, self::TYPE_WRITE_OFF,
        self::TYPE_RETURN_CREDIT, self::TYPE_VOID_REVERSAL, self::TYPE_ADJUSTMENT,
    ];

    protected $fillable = [
        'customer_id', 'type', 'sale_id', 'customer_payment_id', 'return_id',
        'amount_usd', 'entry_date', 'user_id', 'notes',
    ];

    protected $casts = [
        'amount_usd' => 'decimal:4',
        'entry_date' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(CustomerPayment::class, 'customer_payment_id');
    }

    public function typeLabel(): string
    {
        return \App\Support\EnumLabel::get('ledger_entry', $this->type);
    }
}
