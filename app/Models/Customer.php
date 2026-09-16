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

    protected $fillable = [
        'uuid', 'name', 'phone', 'email', 'address',
        'company_name', 'tax_number', 'customer_group',
        'credit_limit', 'balance', 'loyalty_points', 'loyalty_tier',
        'tax_exempt', 'is_active', 'birth_date', 'notes',
    ];

    protected $casts = [
        'credit_limit' => 'decimal:2',
        'balance' => 'decimal:2',
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
        'balance', 'loyalty_points', 'credit_limit', 'tax_exempt',
    ];

    /** @return array<string, mixed> */
    public function posSummary(): array
    {
        return $this->only(self::POS_COLUMNS);
    }

    public function hasAvailableCredit(float $amount): bool
    {
        return ($this->balance + $amount) <= $this->credit_limit;
    }
}
