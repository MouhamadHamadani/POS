<?php

namespace App\Models\Concerns;

use InvalidArgumentException;

/**
 * Pack / package / carton selling for a Product (client/tobacco, docs/packaging.md).
 *
 * Stock is always counted in packs. A "package" is `package_size` packs (a sleeve of 10), a "carton" is
 * `packages_per_carton` packages. The pack has the product's own barcode, the package has none (the cashier
 * reaches it from the pack's barcode), the carton has `carton_barcode`.
 *
 * Everything here is inert while config('pos.features.packaging') is off: supportsUnit() then allows only
 * 'pack', so a product row that still carries packaging data (the flag was switched off later) sells exactly
 * like a plain one. The columns are merged into $fillable / $casts by initializeHasPackaging(), which keeps
 * Product.php itself at one `use` line.
 */
trait HasPackaging
{
    public const UNIT_PACK = 'pack';
    public const UNIT_PACKAGE = 'package';
    public const UNIT_CARTON = 'carton';
    public const SALE_UNITS = [self::UNIT_PACK, self::UNIT_PACKAGE, self::UNIT_CARTON];

    /** Retail / wholesale / VIP price columns of the package and carton, same order as Customer::GROUPS. */
    private const UNIT_PRICE_COLUMNS = [
        self::UNIT_PACKAGE => ['retail' => 'package_price_usd', 'wholesale' => 'package_wholesale_price_usd', 'vip' => 'package_vip_price_usd'],
        self::UNIT_CARTON => ['retail' => 'carton_price_usd', 'wholesale' => 'carton_wholesale_price_usd', 'vip' => 'carton_vip_price_usd'],
    ];

    public function initializeHasPackaging(): void
    {
        $this->mergeFillable([
            'has_package', 'package_size',
            'package_price_usd', 'package_wholesale_price_usd', 'package_vip_price_usd',
            'carton_barcode', 'packages_per_carton', 'sell_carton_at_pos',
            'carton_price_usd', 'carton_wholesale_price_usd', 'carton_vip_price_usd',
        ]);

        $this->mergeCasts([
            'has_package' => 'boolean',
            'sell_carton_at_pos' => 'boolean',
            'package_size' => 'integer',
            'packages_per_carton' => 'integer',
            'package_price_usd' => 'decimal:4',
            'package_wholesale_price_usd' => 'decimal:4',
            'package_vip_price_usd' => 'decimal:4',
            'carton_price_usd' => 'decimal:4',
            'carton_wholesale_price_usd' => 'decimal:4',
            'carton_vip_price_usd' => 'decimal:4',
        ]);
    }

    public static function packagingEnabled(): bool
    {
        return (bool) config('pos.features.packaging');
    }

    /** What the till needs on top of the usual product columns. Empty with the flag off, so the page is unchanged. */
    public static function packagingColumns(): array
    {
        return static::packagingEnabled()
            ? [
                'has_package', 'package_size',
                'package_price_usd', 'package_wholesale_price_usd', 'package_vip_price_usd',
                'carton_barcode', 'packages_per_carton', 'sell_carton_at_pos',
                'carton_price_usd', 'carton_wholesale_price_usd', 'carton_vip_price_usd',
            ]
            : [];
    }

    /** Packs in one carton, or null when the product has no carton set up. */
    public function cartonPacks(): ?int
    {
        if (! $this->definesPackage() || blank($this->carton_barcode) || (int) $this->packages_per_carton < 1) {
            return null;
        }

        return (int) $this->package_size * (int) $this->packages_per_carton;
    }

    /** The product is configured with this unit (whatever the flag or the sell-at-the-till switch say). */
    public function definesUnit(string $unit): bool
    {
        return match ($unit) {
            self::UNIT_PACK => true,
            self::UNIT_PACKAGE => $this->definesPackage(),
            self::UNIT_CARTON => $this->cartonPacks() !== null,
            default => false,
        };
    }

    /** The unit may be rung up at the till: the flag is on, it is configured, priced and (carton) switched on. */
    public function supportsUnit(string $unit): bool
    {
        if ($unit === self::UNIT_PACK) {
            return true;
        }
        if (! static::packagingEnabled() || ! $this->definesUnit($unit)) {
            return false;
        }

        $retail = self::UNIT_PRICE_COLUMNS[$unit]['retail'];
        if ($this->{$retail} === null) {
            return false;
        }

        return $unit !== self::UNIT_CARTON || (bool) $this->sell_carton_at_pos;
    }

    /** Units stock can be received in: the pack always, the rest when the flag is on and the product defines them. */
    public function receivableUnits(): array
    {
        return array_values(array_filter(
            self::SALE_UNITS,
            fn (string $u) => $u === self::UNIT_PACK || (static::packagingEnabled() && $this->definesUnit($u)),
        ));
    }

    /** Packs per sale unit. Throws for a unit the product does not define: a caller must have checked supportsUnit(). */
    public function multiplierFor(string $unit): float
    {
        if (! $this->definesUnit($unit)) {
            throw new InvalidArgumentException("Product {$this->id} has no '{$unit}' unit.");
        }

        return match ($unit) {
            self::UNIT_PACKAGE => (float) $this->package_size,
            self::UNIT_CARTON => (float) $this->cartonPacks(),
            default => 1.0,
        };
    }

    /**
     * Price of ONE sale unit on a price list. A missing wholesale / VIP price falls back to the unit's own retail
     * price, the same rule as Product::priceForGroup(). The pack is priceForGroup() itself.
     */
    public function priceForUnit(string $unit, string $tier): float
    {
        $cols = self::UNIT_PRICE_COLUMNS[$unit] ?? null;
        if ($cols === null) {
            return $this->priceForGroup($tier);
        }

        $retail = $this->{$cols['retail']};

        return (float) match ($tier) {
            'wholesale', 'vip' => $this->{$cols[$tier]} ?? $retail,
            default => $retail,
        };
    }

    private function definesPackage(): bool
    {
        return $this->type === self::TYPE_SIMPLE && (bool) $this->has_package && (int) $this->package_size >= 2;
    }
}
