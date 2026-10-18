# CLAUDE.md — POS Repository

## Status: ACTIVE DEVELOPMENT

This is the live, production POS codebase — a Laravel 11 + NativePHP (Electron)
desktop point-of-sale app for Lebanese retail (dual USD/LBP currency, 11% VAT,
Arabic/English UI, offline-first via SQLite). It is being actively built out
for client delivery. Edit, add, and commit freely — this is no longer a
frozen reference repo.

(A separate, unrelated initiative — "MENA Business OS", a multi-tenant SaaS —
previously used this repo as a read-only pattern reference. That work happens
in a different repo and has no bearing on this one. Ignore any earlier notes
about this repo being read-only; they no longer apply.)

## Stack

- Laravel 11, PHP 8.2+, SQLite (offline-first)
- NativePHP v2 (Electron) for desktop packaging — see `config/nativephp.php`
  and `app/Providers/NativeAppServiceProvider.php`
- Blade + Alpine.js + Tailwind CSS + Vite (no Livewire, no Filament in this app)
- barryvdh/laravel-dompdf for report PDFs, mike42/escpos-php for thermal
  receipt printing, picqer/php-barcode-generator for barcodes
- Testing: Pest / PHPUnit (`tests/Feature`, `tests/Unit`)

## Core conventions — follow these, don't reinvent them

- Settings are read/written via `Setting::get()` / `Setting::set()`, never
  raw config or DB queries.
- `App\Services\CurrencyService` is the only place currency conversion and
  LBP rounding (`lbp_rounding_step`) happens. USD is the stored base currency.
- `App\Models\AuditLog::record()` is called on every mutating action (sales,
  stock adjustments, settings changes, user management, etc.). New mutating
  endpoints must call it too.
- Bilingual model fields follow the `name` / `name_ar` pattern with a
  `localName()` accessor — extend this pattern for any new translatable data,
  don't invent a new one.
- The 14-day trial lock lives in `App\Support\Activation` + `ActivationGate`
  (a web-group middleware, prioritised ahead of `auth`). Status `trial` is written
  only by `SetupController`; an unset status means `activated`, so installs that
  predate it never lock. Unlock is `super_admin`-only (Settings → Plan) and the
  lock never deletes data. The same tab can lock an install by hand
  (`Activation::deactivate()`, password + tick box); `activation_lock_reason`
  (`trial_expired` | `manual`) picks the lock-screen text, and a locked install
  with no reason reads as `trial_expired`. A migration backfills `activation_status`
  / `installed_at` / `tier` on installs that predate them; the seeder must not
  carry them (it would switch the trial off).
- Four roles: `admin`, `manager`, `cashier`, `stock`, enforced via the
  `role:` route middleware (`App\Http\Middleware\RoleMiddleware`).
- VAT and other tax rates are stored as `0..1` decimals, not percentages.
- Business logic lives in `app/Services/*`, not in controllers.
- Prices and discount limits are server-authoritative. `SaleService` prices every
  line from `Product::priceForGroup($tier)`; the sell screen sends a `price_tier`
  and nothing else about price, with one exception: a cashier **price override**
  (below). Who may use which tier and how much discount comes from
  `User::canChangePriceTier()` / `maxDiscountPct()`.
- **Price override** (`App\Services\PriceOverrideService`, setting-gated). A cart
  line may carry `unit_price` (+ `original_unit_price`, `price_reason`,
  `price_approval`): the one price the browser may propose, for that line in this
  sale only. The service re-derives the list price and returns 422 on
  `cart.N.price` unless the user may do it: the feature is on
  (`price_override_enabled`), a cut is within `price_override_max_decrease_pct`
  (managers and up are unlimited), a reason is given when
  `price_override_require_reason`, and the product allows discounts. A deeper cut
  needs a manager/admin/super_admin to vouch at the till
  (`POST /pos/api/price-approval`, login + PIN or password, throttled): that
  returns an encrypted, expiring, **single-use** approval tied to cashier +
  product + price, which travels in the cart (so holds keep it) and is spent when
  the sale is saved (`sale_items.price_approval_ref`, unique). Charged price =
  `unit_price_usd`; the list price at sale time = `original_unit_price_usd`;
  `price_overridden_by` marks an overridden line. Line and cart discounts stack on
  the overridden price. Every overridden line is audited (`price_override`);
  Reports -> Price Overrides lists them. Refunds, reports and receipts already
  read the charged price. Thermal and A4 receipts also print the list price
  struck through ("was") unless `price_override_show_original` is off
  (`SaleItem::receiptWasPrice()`); custom receipt templates (`ReceiptRenderer`)
  show the charged price only.
- `SaleService::totalsFromCart()` and `computeCart()` in `pos/sell.blade.php` are
  the same arithmetic in PHP and JS (per-line 4-dp rounding, cart-discount
  allocation, tax-exempt). Change both together — `CartMathParityTest` runs the
  real JS through Node and fails if they drift.

## i18n / RTL

The UI is fully bilingual (`en` / `ar`). Resolution order (`SetLocale`): the
user's `language` -> the session (`/locale/{locale}`, used on login/setup) ->
the store `language` setting -> `en`. Western digits (0-9) are used in both.

- **Catalogs**: one file per area in `lang/en/` and `lang/ar/` (`nav`, `common`,
  `pos`, `products`, `settings`, `reports`, `receipt`, `messages`, `enums`, ...), plus
  `lang/{en,ar}.json` for the Breeze sentence keys and framework error pages.
  Use `__('area.key', ['name' => ...])` with `:placeholders` and `trans_choice` for
  plurals -- never concatenate translated fragments. A new string needs both
  languages; `tests/Unit/LangParityTest.php` fails on a missing key, a mismatched
  placeholder or an untranslated Arabic value, and
  `tests/Unit/NoHardcodedStringsTest.php` fails on English text left in a view.
  Arabic wording follows the glossary already in `lang/ar/*` (reuse the same word).
- **Sell screen**: all strings live in `pos.ui` and are read with the Alpine `t()`
  helper -- don't add a second JS i18n mechanism.
- **Enum-ish values** (statuses, roles, reasons, payment methods...) are shown with
  the model's `...Label()` / `App\Support\EnumLabel::get($group, $value)`, which reads
  `enums.{group}.{value}` and falls back to a headline of the raw value. Keep badge
  colours keyed on the raw value.
- **Server messages** (flash, `ValidationException`, JSON errors) go through
  `messages.*`. Error *keys* (`cart`, `payment`, `cart.N.discount`...) never change.
  Audit-log descriptions stay English.
- **Layout**: use Tailwind logical utilities (`text-start`, `ms-`/`me-`, `ps-`/`pe-`,
  `start-`/`end-`, `border-s`, `rounded-s`, `space-x-* rtl:space-x-reverse`), flip
  directional icons with `rtl:-scale-x-100`, and wrap amounts, quantities, SKUs,
  barcodes, phone numbers, dates and document numbers in `<x-ltr>`. Numeric/code
  inputs get `dir="ltr"`. Don't change `computeCart()` arithmetic for display reasons.
- **Bilingual data**: `name` / `name_ar` + `localName()`; eager loads that feed a
  screen must select `name_ar` too.
- **Receipts** follow the store setting `receipt_language` (`en` | `ar` | `bilingual`),
  not the cashier's UI language. Cairo ships in `public/fonts` (offline).
- **PDF reports** (dompdf) can't shape Arabic: every string goes through `@pdf()`
  (`App\Support\PdfText`, ar-php) and RTL tables emit columns in reverse. Amiri
  ships in `resources/fonts`. Export filenames stay ASCII (slug of the English title).
- **Fonts** (Inter, Cairo) come from `@fontsource` via Vite -- nothing loads from the
  internet. Desktop menu labels come from `lang/*/native.php` using the store
  language (applied at the next app restart).

## Testing

- `tests/Feature` has coverage for auth, profile, barcode lookup, hold/recall,
  receipt printing, sale authorization, and product CRUD.
- No coverage yet for: returns, purchase orders/suppliers, reports, settings/
  backups, customers/loyalty, shifts, or `CurrencyService` rounding edge cases.
  New features in these areas should ship with a Pest feature test.
- Run `php artisan test` (or `vendor/bin/pest`) before considering any task done.

## Packaging / delivery

- NativePHP builds are configured with an auto-updater (`config/nativephp.php`)
  supporting GitHub/S3/DigitalOcean Spaces channels — confirm which channel
  is actually wired with real credentials before relying on auto-update.
- Code signing (Windows Authenticode / Apple notarization) requires a
  purchased certificate or Apple Developer enrollment — this is an external,
  human step, not something to script around. Ship unsigned builds only
  when explicitly told that's acceptable for the current milestone.

## Client builds

A client build is a profile, not a fork — same codebase, same pattern as the
demo build. `POS_CLIENT=<name>` (`config('pos.client')`, default `null` = plain
LebaSouk) activates `config/clients/<name>.php` and `resources/clients/<name>/`.
Views and controllers go through `App\Support\Client` (`is()`, `config()`,
`view()`), never inline string checks. `Client::view($name, $fallback)` returns
`resources/clients/<name>/views/<name>.blade.php` if it exists, else the core
view. First-run setup writes the profile's `settings` block once
(`Client::seedSettings()`); after that the admin owns those values.

Only content that can't be config or a template belongs on a `client/<name>`
branch; keep it rebased on `main`.

**Vortex Energy** (`POS_CLIENT=vortex`, `.env.vortex.example`, app id
`com.buildsyntax.lebasouk.vortex`, app name "Vortex Energy POS"). One unsigned
Windows installer goes on both store computers. Each install creates its own
SQLite database under `%APPDATA%\Vortex Energy POS\` on first run and runs
first-run setup separately — two `super_admin` setups, two store admins. The
stores do not sync. Build on Windows:

```bash
cp .env.vortex.example .env && php artisan key:generate
```

Bump `NATIVEPHP_APP_VERSION` in `.env`, then:

```bash
php artisan native:build win x64
```

`native:build` aborts if `.env` isn't a production env or `POS_CLIENT` names a
profile with no `config/clients/<name>.php`. Check `dist/` for a freshly
timestamped `Vortex Energy POS-<version>-setup.exe` (it exits 0 even when it
fails — see `docs/demo-build.md`). To build plain LebaSouk again, copy
`.env.example` back over `.env`. A cached config left by the build overrides
`phpunit.xml`; run `php artisan optimize:clear` before `php artisan test`.

## Guardrails (still apply)

- Never read, echo, log or commit secrets from `.env` — `APP_KEY`, DB
  credentials, mail/cloud keys, and the code-signing and updater provider
  credentials. Don't paste them into a commit, a PR body, or a chat reply.
- Editing a *non-secret* key in `.env` (`APP_NAME`, `APP_ENV`, `APP_DEBUG`,
  `NATIVEPHP_APP_VERSION`, `POS_*`) is fine when the task calls for it. Change
  the one line, leave the rest of the file alone, and mirror the change into
  `.env.example` — that one *is* committed, so it's what a fresh checkout and
  the build machine actually get. `.env` itself is gitignored, so a change
  there is local to one machine and never reaches a PR.
- Don't edit already-shipped migrations — add new ones instead.
- Don't touch `storage/` contents directly.
- Keep the audit log and currency/VAT conventions above consistent across
  any new code.
