<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    use HasFactory, HasUuid;

    public const STATUS_COMPLETED = 'completed';
    public const STATUS_VOIDED = 'voided';
    public const STATUS_REFUNDED = 'refunded';
    public const STATUS_PARTIAL_REFUND = 'partial_refund';
    public const STATUS_ON_HOLD = 'on_hold';

    public const METHOD_CASH_USD = 'cash_usd';
    public const METHOD_CASH_LBP = 'cash_lbp';
    public const METHOD_CARD = 'card';
    public const METHOD_MIXED = 'mixed';
    public const METHOD_CREDIT = 'credit';
    public const METHOD_SPLIT = 'split';

    /**
     * Where the sale came from. `online` is an order the store took by WhatsApp / Instagram /
     * Facebook / phone and rang up by hand; it is not a website integration. It changes no price,
     * stock, tax or loyalty rule, it only lets History and the reports tell the two apart.
     */
    public const CHANNEL_ONSITE = 'onsite';
    public const CHANNEL_ONLINE = 'online';

    public const CHANNELS = [self::CHANNEL_ONSITE, self::CHANNEL_ONLINE];

    /** Only meaningful with CHANNEL_ONLINE. */
    public const CHANNEL_SOURCES = ['whatsapp', 'facebook', 'instagram', 'phone', 'other'];

    public const STATUSES = [
        self::STATUS_COMPLETED, self::STATUS_PARTIAL_REFUND, self::STATUS_REFUNDED,
        self::STATUS_VOIDED, self::STATUS_ON_HOLD,
    ];

    public const METHODS = [
        self::METHOD_CASH_USD, self::METHOD_CASH_LBP, self::METHOD_CARD,
        self::METHOD_MIXED, self::METHOD_CREDIT, self::METHOD_SPLIT,
    ];

    protected $fillable = [
        'uuid', 'receipt_number', 'customer_id', 'user_id', 'shift_id', 'price_tier',
        'channel', 'channel_source',
        'subtotal_usd', 'discount_amount_usd', 'cart_discount_usd', 'tax_amount_usd',
        'total_usd', 'total_lbp', 'exchange_rate',
        'payment_method',
        'amount_tendered_usd', 'amount_tendered_lbp',
        'amount_card_usd', 'amount_credit_usd',
        'change_usd', 'change_lbp',
        'loyalty_points_earned', 'loyalty_points_redeemed',
        'card_type', 'card_reference',
        'status', 'notes', 'is_synced',
        'voided_at', 'voided_by',
    ];

    protected $casts = [
        'subtotal_usd' => 'decimal:4',
        'discount_amount_usd' => 'decimal:4',
        'cart_discount_usd' => 'decimal:4',
        'tax_amount_usd' => 'decimal:4',
        'total_usd' => 'decimal:4',
        'total_lbp' => 'decimal:2',
        'exchange_rate' => 'decimal:6',
        'amount_tendered_usd' => 'decimal:4',
        'amount_tendered_lbp' => 'decimal:2',
        'amount_card_usd' => 'decimal:4',
        'amount_credit_usd' => 'decimal:4',
        'change_usd' => 'decimal:4',
        'change_lbp' => 'decimal:2',
        'is_synced' => 'boolean',
        'voided_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(ReturnTransaction::class, 'sale_id');
    }

    /**
     * The one ownership rule for reading and reprinting a sale: managerial roles
     * see every sale, anyone else only the ones they rang up. Query side
     * (scopeVisibleTo) and record side (canBeSeenBy) are the same rule — every
     * receipt, sale-JSON and sales-history check goes through one of them.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->isManagerial() ? $query : $query->where($query->qualifyColumn('user_id'), $user->id);
    }

    public function canBeSeenBy(User $user): bool
    {
        return $user->isManagerial() || $this->user_id === $user->id;
    }

    /** A held cart has no receipt yet; every other status can be reprinted, voided included. */
    public function hasReceipt(): bool
    {
        return $this->status !== self::STATUS_ON_HOLD;
    }

    public function statusLabel(): string
    {
        return \App\Support\EnumLabel::get('sale_status', $this->status);
    }

    public function paymentMethodLabel(): string
    {
        return \App\Support\EnumLabel::get('payment_method', $this->payment_method);
    }

    /**
     * The one rule for what channel a request may record: the `sales_channels` feature off means
     * everything is on-site (what was sent is ignored, not an error: selling must work either
     * way), and a source is kept only with `online` and only if it is one we know.
     *
     * @return array{0:string,1:?string} [channel, channel_source]
     */
    public static function resolveChannel(?string $channel, ?string $source): array
    {
        if (! feature('sales_channels') || $channel !== self::CHANNEL_ONLINE) {
            return [self::CHANNEL_ONSITE, null];
        }

        return [self::CHANNEL_ONLINE, in_array($source, self::CHANNEL_SOURCES, true) ? $source : null];
    }

    public function isOnline(): bool
    {
        return $this->channel === self::CHANNEL_ONLINE;
    }

    public function channelLabel(): string
    {
        return \App\Support\EnumLabel::get('sale_channel', $this->channel);
    }

    public function channelSourceLabel(): ?string
    {
        return $this->channel_source ? \App\Support\EnumLabel::get('sale_channel_source', $this->channel_source) : null;
    }
}
