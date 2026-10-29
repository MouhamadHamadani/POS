<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_SUPER_ADMIN = 'super_admin';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_MANAGER = 'manager';
    public const ROLE_CASHIER = 'cashier';
    public const ROLE_STOCK = 'stock';

    /** role value => label. Order matters: most privileged first. */
    public const ROLES = [
        self::ROLE_SUPER_ADMIN => 'Super Admin',
        self::ROLE_ADMIN => 'Admin',
        self::ROLE_MANAGER => 'Manager',
        self::ROLE_CASHIER => 'Cashier',
        self::ROLE_STOCK => 'Stock Keeper',
    ];

    /** Roles an `admin` (client store owner) may assign to their own staff. */
    public const STAFF_ROLES = [
        self::ROLE_MANAGER => 'Manager',
        self::ROLE_CASHIER => 'Cashier',
        self::ROLE_STOCK => 'Stock Keeper',
    ];

    /** Interface size choices, as a percentage of the browser's root font size. NULL / anything else is 100. */
    public const UI_SCALES = [90, 100, 110, 125];

    /**
     * Not in $fillable on purpose: the only writer is ProfileController::updateUiScale, which sets
     * it on the signed-in user alone. A user create / edit form can never carry it.
     */
    protected $fillable = [
        'uuid',
        'name',
        'username',
        'email',
        'password',
        'role',
        'pin',
        'language',
        'is_active',
        'max_discount_pct',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'pin',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'pin' => 'hashed',
            'is_active' => 'boolean',
            'max_discount_pct' => 'decimal:2',
            'ui_scale' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            $user->uuid ??= (string) Str::uuid();
        });
    }

    /** This user's interface size in percent: their own choice, or 100. */
    public function uiScale(): int
    {
        return in_array($this->ui_scale, self::UI_SCALES, true) ? $this->ui_scale : 100;
    }

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(self::ROLE_ADMIN, self::ROLE_SUPER_ADMIN);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPER_ADMIN;
    }

    /**
     * Managerial tier — may view and act on *other* users' sales, receipts,
     * holds and returns. Keep every such check routed through here so a new
     * role tier lands in one place.
     */
    public function isManagerial(): bool
    {
        return $this->hasRole(self::ROLE_SUPER_ADMIN, self::ROLE_ADMIN, self::ROLE_MANAGER);
    }

    /**
     * Highest effective discount % (total line discount ÷ line gross) this user
     * may give at the POS. Managerial roles are unlimited; everyone else gets
     * their own cap, or the store default from Settings when theirs is 0.
     * The one source for the sell screen and the server-side check.
     */
    public function maxDiscountPct(): float
    {
        if ($this->isManagerial()) {
            return 100.0;
        }

        $own = (float) $this->max_discount_pct;

        return $own > 0
            ? $own
            : (float) Setting::get('max_cashier_discount_pct', config('pos.pos.max_cashier_discount_pct'));
    }

    /** May this user pick a price list other than the attached customer's? */
    public function canChangePriceTier(): bool
    {
        return $this->isManagerial() || (bool) Setting::get('cashier_can_change_price_tier', true);
    }

    /**
     * Roles that get the Sales History screens (route middleware, sidebar and the
     * receipt-number links all read this list). `stock` is left out: it has no
     * sales screens elsewhere either. Which sales a role may open is Sale::canBeSeenBy.
     */
    public const SALES_HISTORY_ROLES = [self::ROLE_ADMIN, self::ROLE_MANAGER, self::ROLE_CASHIER];

    public function canViewSalesHistory(): bool
    {
        return $this->isSuperAdmin() || $this->hasRole(...self::SALES_HISTORY_ROLES);
    }

    /** Roles that may only be created or edited by a super-admin. */
    public function isPrivileged(): bool
    {
        return $this->hasRole(self::ROLE_ADMIN, self::ROLE_SUPER_ADMIN);
    }

    /**
     * Bulk product import. Only `admin` and `stock` are ever eligible, and each
     * of those two is separately switched on by a super-admin (see the
     * Permissions tab in Settings). super_admin itself is always allowed —
     * same principle as RoleMiddleware: the vendor account can't be locked out
     * of a feature it has to support.
     */
    public function canBulkUploadProducts(): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return match ($this->role) {
            self::ROLE_ADMIN => (bool) Setting::get('bulk_upload_enabled_admin', false),
            self::ROLE_STOCK => (bool) Setting::get('bulk_upload_enabled_stock', false),
            default => false,
        };
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function currentShift()
    {
        return $this->hasOne(Shift::class)->where('status', 'open')->latestOfMany();
    }

    public function roleLabel(): string
    {
        return \App\Support\EnumLabel::get('role', $this->role);
    }
}
