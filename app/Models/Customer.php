<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    public const GROUP_RETAIL = 'retail';
    public const GROUP_WHOLESALE = 'wholesale';
    public const GROUP_VIP = 'vip';

    /** Customer groups double as POS price lists (Product::priceForGroup). */
    public const GROUPS = [self::GROUP_RETAIL, self::GROUP_WHOLESALE, self::GROUP_VIP];

    protected $fillable = [
        'uuid', 'name', 'phone', 'email', 'address',
        'company_name', 'tax_number', 'customer_group',
        'credit_limit', 'balance', 'loyalty_points', 'loyalty_tier',
        'tax_exempt', 'is_active', 'birth_date', 'notes', 'payment_terms_days',
    ];

    protected $casts = [
        'credit_limit' => 'decimal:2',
        'balance' => 'decimal:2',
        'payment_terms_days' => 'integer',
        'loyalty_points' => 'integer',
        'tax_exempt' => 'boolean',
        'is_active' => 'boolean',
        'birth_date' => 'date',
    ];

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function loyaltyTransactions(): HasMany
    {
        return $this->hasMany(LoyaltyTransaction::class);
    }

    /**
     * Columns the POS customer chip needs. Kept here so the typeahead
     * (CustomerController::search) and a recalled hold hand the sell screen
     * the same shape — the frontend renders both with one template.
     */
    public const POS_COLUMNS = [
        'id', 'name', 'phone', 'customer_group',
        'balance', 'loyalty_points', 'credit_limit', 'tax_exempt', 'payment_terms_days',
    ];

    /**
     * The chip's data. With the debt book on it also says how much of what the customer owes is past due
     * (`overdue_usd`), so the till can flag an overdue customer before putting more on their tab.
     *
     * @return array<string, mixed>
     */
    public function posSummary(): array
    {
        $summary = $this->only(self::POS_COLUMNS);
        $summary['overdue_usd'] = feature('customer_credit') && (float) $this->balance > 0
            ? app(\App\Services\CustomerLedgerService::class)->overdueOf($this)
            : 0.0;

        return $summary;
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(CustomerLedgerEntry::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(CustomerPayment::class);
    }

    /**
     * Would `$amount` more on account stay within the credit limit? What is already owed counts
     * (balance + amount <= limit); half a cent of slack, the till's own rounding. A limit of 0 means
     * no credit at all.
     */
    public function hasAvailableCredit(float $amount): bool
    {
        return ((float) $this->balance + $amount) <= (float) $this->credit_limit + 0.005;
    }

    /** What more can go on account right now (never negative). */
    public function availableCredit(): float
    {
        return max(0.0, round((float) $this->credit_limit - (float) $this->balance, 2));
    }

    /** Days of credit this customer gets: their own terms, else the store default. */
    public function creditDays(): int
    {
        return (int) ($this->payment_terms_days ?? Setting::get('default_credit_days', 30));
    }

    public function groupLabel(): string
    {
        return \App\Support\EnumLabel::get('customer_group', $this->customer_group);
    }

    public function tierLabel(): string
    {
        return \App\Support\EnumLabel::get('loyalty_tier', $this->loyalty_tier);
    }
}
