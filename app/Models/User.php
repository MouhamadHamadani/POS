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
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            $user->uuid ??= (string) Str::uuid();
        });
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
}
