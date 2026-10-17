<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\SaleItem;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Cashier price override: raise or lower one line's unit price for the current sale only.
 *
 * The catalog price never changes. The browser proposes a price per line (`unit_price`); this
 * class decides whether it stands. Everything is re-derived here from the product row and the
 * price list, so a hand-built request gets exactly what a cashier at the till would.
 *
 *  - raising a price: always allowed (while the feature is on);
 *  - lowering it by up to the cashier limit: allowed; a reason is required when the store says so;
 *  - lowering it further: needs a manager, admin or super admin. That person proves themselves
 *    once, through grant(), and gets back a signed, expiring, single-use approval tied to this
 *    cashier, this product and this exact price. The approval rides in the cart (so a held cart
 *    keeps it) and is spent when the sale is saved.
 *
 * Managerial users are not limited. A product with discounts switched off cannot be marked down.
 */
class PriceOverrideService
{
    /** Half of the last stored decimal: slack for comparing 4-dp USD prices. */
    private const EPS = 0.00005;

    /** Slack for comparing percentages, so a cut of exactly the limit passes. */
    private const PCT_EPS = 0.000001;

    /** A manager's approval stays valid this long. */
    private const GRANT_MINUTES = 240;

    /** Wrong manager credentials per login before approvals for it are locked. */
    private const MAX_FAILURES = 5;

    private const LOCK_SECONDS = 900;

    public function enabled(): bool
    {
        return (bool) Setting::get('price_override_enabled', true);
    }

    public function maxDecreasePct(): float
    {
        return (float) Setting::get('price_override_max_decrease_pct', 20);
    }

    public function requireReason(): bool
    {
        return (bool) Setting::get('price_override_require_reason', true);
    }

    /** The biggest cut, in % of the list price, this user may make without a manager. */
    public function limitFor(User $user): float
    {
        return $user->isManagerial() ? 100.0 : $this->maxDecreasePct();
    }

    /** What the sell screen needs to know, for this user. */
    public function configFor(User $user): array
    {
        return [
            'enabled' => $this->enabled(),
            'limit' => $this->limitFor($user),
            'requireReason' => $this->requireReason(),
        ];
    }

    /**
     * Validate the price override on each cart line.
     *
     * Returns the lines that really are overridden, keyed by their position in the cart:
     *   [i => ['price', 'original', 'change_pct', 'reason', 'approved_by', 'approval_ref']]
     * A line with no `unit_price`, or one equal to the list price, is not an override.
     *
     * @throws ValidationException on the first line that may not be sold at its price
     */
    public function resolve(array $cart, string $tier, User $user): array
    {
        $out = [];

        foreach (array_values($cart) as $i => $row) {
            $raw = $row['unit_price'] ?? null;
            if ($raw === null || $raw === '') {
                continue;
            }

            $product = $row['product'] ?? Product::find($row['product_id'] ?? 0);
            if (!$product) {
                continue; // SaleService::totalsFromCart reports the missing product
            }

            $override = $this->check($i, $product, $tier, $raw, $row, $user);
            if ($override) {
                $out[$i] = $override;
            }
        }

        return $out;
    }

    private function check(int $i, Product $product, string $tier, mixed $raw, array $row, User $user): ?array
    {
        $name = $product->localName();

        if (!is_numeric($raw) || (float) $raw <= 0) {
            throw $this->fail($i, 'price_invalid', ['product' => $name]);
        }

        $price = round((float) $raw, 4);
        $catalog = $product->priceForGroup($tier);

        if (abs($price - $catalog) <= self::EPS) {
            return null; // the list price: nothing was overridden
        }

        if (!$this->enabled()) {
            throw $this->fail($i, 'price_override_disabled', ['product' => $name]);
        }

        // The cashier (or an approver) saw a different list price than the one in force now.
        $seen = $row['original_unit_price'] ?? null;
        if ($seen !== null && $seen !== '' && abs((float) $seen - $catalog) > self::EPS) {
            throw $this->fail($i, 'price_changed', ['product' => $name, 'price' => number_format($catalog, 2)]);
        }

        $reason = isset($row['price_reason']) ? mb_substr(trim((string) $row['price_reason']), 0, 255) : '';
        $approvedBy = $ref = null;

        if ($price < $catalog) {
            if (!$product->allow_discount) {
                throw $this->fail($i, 'price_cut_not_allowed', ['product' => $name]);
            }
            if ($reason === '' && $this->requireReason()) {
                throw $this->fail($i, 'price_reason_required', ['product' => $name]);
            }

            $cut = ($catalog - $price) / $catalog * 100;
            $limit = $this->limitFor($user);
            if ($cut > $limit + self::PCT_EPS) {
                [$approvedBy, $ref] = $this->redeem($i, $row['price_approval'] ?? null, $user, $product, $price, $cut, $limit);
            }
        }

        return [
            'price' => $price,
            'original' => $catalog,
            'change_pct' => $catalog > 0 ? round(($price - $catalog) / $catalog * 100, 4) : 0.0,
            'reason' => $reason !== '' ? $reason : null,
            'approved_by' => $approvedBy,
            'approval_ref' => $ref,
        ];
    }

    /** Check the manager approval carried by a line; returns [approver id, approval nonce]. */
    private function redeem(int $i, mixed $token, User $cashier, Product $product, float $price, float $cut, float $limit): array
    {
        $grant = $this->readGrant($token);
        $approver = $grant ? User::find($grant['a']) : null;

        $valid = $grant
            && $grant['u'] === $cashier->id
            && $grant['p'] === $product->id
            && $grant['v'] === $this->fixed($price)
            && $approver?->is_active
            && $approver->isManagerial();

        if (!$valid) {
            throw $this->fail($i, 'price_needs_approval', [
                'product' => $product->localName(), 'pct' => round($cut, 2), 'limit' => round($limit, 2),
            ]);
        }

        if (SaleItem::where('price_approval_ref', $grant['n'])->exists()) {
            throw $this->fail($i, 'price_approval_used', ['product' => $product->localName()]);
        }

        return [$approver->id, $grant['n']];
    }

    /**
     * A manager vouches for one price on one product for one cashier.
     *
     * $login is a username or email, $secret the manager's PIN or password. Wrong guesses are
     * counted per login (a 4-digit PIN is guessable) and lock approvals for it for a while.
     *
     * @return array{token: string, approver: array{id: int, name: string}, expires_at: string}
     * @throws ValidationException
     */
    public function grant(User $cashier, Product $product, float $price, string $login, string $secret): array
    {
        $key = 'price-approval:' . Str::lower($login);
        if (RateLimiter::tooManyAttempts($key, self::MAX_FAILURES)) {
            throw ValidationException::withMessages([
                'approver' => __('pos.errors.approval_locked', ['minutes' => (int) ceil(RateLimiter::availableIn($key) / 60)]),
            ]);
        }

        $approver = User::query()
            ->where(fn ($q) => $q->where('username', $login)->orWhere('email', $login))
            ->where('is_active', true)
            ->first();

        $known = $approver?->isManagerial()
            && (($approver->pin && Hash::check($secret, $approver->pin)) || Hash::check($secret, $approver->password));

        if (!$known) {
            RateLimiter::hit($key, self::LOCK_SECONDS);
            throw ValidationException::withMessages(['approver' => __('pos.errors.approval_denied')]);
        }
        RateLimiter::clear($key);

        $expires = now()->addMinutes(self::GRANT_MINUTES);
        $token = Crypt::encryptString(json_encode([
            'a' => $approver->id, 'u' => $cashier->id, 'p' => $product->id,
            'v' => $this->fixed($price), 'n' => Str::random(32), 'e' => $expires->timestamp,
        ]));

        AuditLog::record($cashier->id, 'price_approval', Product::class, $product->id, null, [
            'approver_id' => $approver->id, 'price' => $this->fixed($price),
        ]);

        return [
            'token' => $token,
            'approver' => ['id' => $approver->id, 'name' => $approver->name],
            'expires_at' => $expires->toIso8601String(),
        ];
    }

    /** @return array{a:int,u:int,p:int,v:string,n:string,e:int}|null null for anything forged, mangled or expired */
    private function readGrant(mixed $token): ?array
    {
        if (!is_string($token) || $token === '') {
            return null;
        }

        try {
            $g = json_decode(Crypt::decryptString($token), true);
        } catch (DecryptException) {
            return null;
        }

        $shape = is_array($g)
            && isset($g['a'], $g['u'], $g['p'], $g['v'], $g['n'], $g['e'])
            && is_int($g['a']) && is_int($g['u']) && is_int($g['p'])
            && is_string($g['v']) && is_string($g['n']) && is_int($g['e']);

        return $shape && $g['e'] > now()->timestamp ? $g : null;
    }

    private function fixed(float $price): string
    {
        return number_format($price, 4, '.', '');
    }

    private function fail(int $i, string $key, array $replace): ValidationException
    {
        return ValidationException::withMessages(["cart.$i.price" => __("pos.errors.$key", $replace)]);
    }
}
