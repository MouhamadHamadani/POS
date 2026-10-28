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
  only by `Activation::startTrial()`, which a `Login` listener in `AppServiceProvider`
  calls on the vendor `super_admin`'s sign-in; an unset status means `activated`, so
  installs that predate it never lock. Unlock is `super_admin`-only (Settings → Plan) and the
  lock never deletes data. The same tab can lock an install by hand
  (`Activation::deactivate()`, password + tick box); `activation_lock_reason`
  (`trial_expired` | `manual`) picks the lock-screen text, and a locked install
  with no reason reads as `trial_expired`. A migration backfills `activation_status`
  / `installed_at` / `tier` on installs that predate them; the seeder must not
  carry them (it would switch the trial off).
- There is no setup screen. The vendor's `super_admin` is built in:
  `App\Support\SuperAdmin::ensure()` creates it from `config('pos.super_admin.*')`
  (`POS_SUPER_ADMIN_USERNAME` / `_NAME` / `_PASSWORD_HASH`) on every launch
  (`NativeAppServiceProvider::bootstrapDatabase`), from `SuperAdminSeeder`, and via
  `pos:ensure-super-admin`. It only creates one while none exists, never in demo
  builds, and never from a missing/non-bcrypt hash (logged; the login page then says
  the install is not provisioned). The hash is written with `setRawAttributes()` to
  bypass the `hashed` cast. It also writes `tier = starter` when none is set, because
  an unset tier reads as `pos.default_tier` (`premium`, for pre-tier installs).
- Plans and features: `config/features.php` is the only place a feature key, its
  group, `requires`, `core` flag, the plan presets and the plan limits (seats, roles) are
  declared. A plan (`starter` / `standard` / `premium`, stored in the `tier` setting) is a
  named preset of keys; a super_admin can also switch single keys per install
  (`feature_overrides` setting, Settings -> Plan & Features). Ask `Feature::enabled($key)` /
  `feature($key)` / `@feature('key')` / the `feature:key` route middleware, never a plan
  name; `License::allows()` is the same call under its old name. Order: demo build (all on) >
  core > override > plan > `requires`. Only `App\Services\FeatureService` writes `tier` and
  `feature_overrides` (it audits and enforces dependencies in both directions). Gate a
  module, not a button inside it: the route group, the sidebar / tab / card that leads to
  it, and any endpoint behind it. Switching a feature off never stops core flows from
  writing data (sales still write stock movements). A new key needs: the config entry,
  presets, `features.labels.*` and `features.groups.*` in both languages, the middleware,
  and a row in `claude/feature-matrix.md` (`FeatureUpgradeAndCatalogueTest` fails if the
  matrix and the config drift). The Plan & Features routes carry no `feature:` key on
  purpose: the vendor is never locked out of them.
- Shifts are optional only when Settings -> POS "Require open shift to sell" is off.
  `sales.shift_id` is nullable for exactly that case: `SaleController` and `SaleService`
  both refuse a shift-less sale while the setting is on, and a cashier who opened a shift
  anyway still gets their sales attached to it. A shift-less sale is a normal sale in every
  report and in Sales History; it simply is in no drawer count (`ShiftService::totals()`
  only reads a shift's own sales). Never assume `$sale->shift` is set.
- Payments: the client sends flat amounts (`amount_usd`, `amount_lbp`, `amount_card`,
  `amount_wish`, `amount_credit`, plus references); `SaleService` derives `payment_method`
  itself (`Sale::deriveMethod`) and never reads the client's `method`. The legacy columns on
  `sales` (tendered, change, card, credit) stay the authoritative **cash-drawer ledger** that
  `ShiftService` reads; `sale_payments` is the itemised record for display and reports, written in
  the same transaction. Card, Wish and on-account must be exact or less than the total and can
  never create change; cash covers only what they leave (`payment_noncash_exceeds`,
  `payment_cash_not_needed`). Card and Wish are bookkeeping only (the cashier takes the money
  in the card machine / Wish app); Wish is USD in v1. Show a sale's payments through
  `Sale::paymentLines()`, which also covers sales that have no rows; report with SQL on
  `sale_payments`. `PaymentModalParityTest` pins the till's modal to the server's rules: change
  the rules in both places together.
- Sales channel: `sales.channel` is `onsite` or `online` ("online" = an order taken by WhatsApp /
  Instagram / Facebook / phone and rung up by hand, not a website integration) with an optional
  `channel_source`. It is bookkeeping only (no price, stock, tax or loyalty rule reads it). Behind
  the `sales_channels` feature (Standard+): off means the server forces on-site
  (`Sale::resolveChannel`) and the till toggle, History filter and report section are hidden, while
  stored values stay readable.
- Fake data mode (`docs/fake-data.md`): `php artisan pos:fake-data` seeds a fake business for
  development and testing; `POS_FAKE_DATA=true` does it on an empty database's first launch; a
  production launch (`APP_ENV=production`) resets a database carrying the `fake_data_seeded` marker
  to a clean install. All of it goes through `App\Support\FakeData`: it refuses in production, in a
  demo build and into a database with data of its own, only a marked database is ever reset, and
  `ReleaseEnv` refuses a build with the flag on. The seeder drives the real services (never raw
  `sales` rows) with a faked clock, so a new money or stock rule needs no seeder change, but a new
  table does need `FakeDataModeTest`'s `BUSINESS_TABLES` and, if it holds reference data a
  migration inserts, an entry in `FakeData::tables()`.
- Five roles: `super_admin` (the vendor's built-in account, no seat), `admin` (the client's owner),
  `manager`, `cashier`, `stock`, enforced via the `role:` route middleware
  (`App\Http\Middleware\RoleMiddleware`, where `super_admin` passes every route that lists any
  role). `admin` is in every plan, Starter included (Starter = 2 seats: the owner plus one more).
  An admin creates and manages manager / cashier / stock users, never an admin-tier account
  (`User::STAFF_ROLES`, `UserController::guardPrivilegedTarget`); only the super_admin creates an
  admin. Settings split, enforced on the server and not by hiding tabs: the admin gets General,
  Currency, Tax, POS, Receipt (printers, test print, receipt template), Numbering, Loyalty,
  Expenses categories and Appearance; **only the super_admin** gets Plan & Features, Backup,
  Permissions and Activation / trial. Those have `role:super_admin` routes of their own, and
  `SettingController::update` (open to the admin for the other groups) refuses a Backup-group
  write with a 403 whether it is posted as the group or smuggled in under another one
  (`SUPER_ADMIN_GROUPS`). A new vendor-only setting goes in its own `role:super_admin` route, or
  in that list. Day-to-day store work (users, reports including P&L, expenses, sales history,
  returns approval) is gated on `admin,manager`, so an admin keeps all of it.
- VAT and other tax rates are stored as `0..1` decimals, not percentages.
- Business logic lives in `app/Services/*`, not in controllers.
- Prices and discount limits are server-authoritative. `SaleService` prices every
  line from `Product::priceForGroup($tier)` and never reads a price from the
  request; the sell screen sends only a `price_tier`. Who may use which tier and
  how much discount comes from `User::canChangePriceTier()` / `maxDiscountPct()`.
- `SaleService::totalsFromCart()` and `computeCart()` in `pos/sell.blade.php` are
  the same arithmetic in PHP and JS (per-line 4-dp rounding, cart-discount
  allocation, tax-exempt). Change both together — `CartMathParityTest` runs the
  real JS through Node and fails if they drift.
- Who may read or reprint a sale is one rule: `Sale::scopeVisibleTo()` (queries) /
  `Sale::canBeSeenBy()` (records) — managerial roles see all, everyone else only their own.
  Receipts, the sale JSON and Sales History all go through it; don't write a new `user_id ===` check.
- Printing: **inside the NativePHP shell** (`ReceiptPrintService::inNativeShell()`) a receipt, an A4
  invoice, a reprint and the Settings test print ALWAYS go silently through Electron
  (`POST /pos/receipts/{sale}/print-thermal|print-a4`, `{printed, desktop}`): to
  `thermal_printer_name` / `a4_printer_name`, or the Windows default printer when empty (the
  `printer` key is then left out of the call). There is never a popup or a print dialog there;
  a failed print is a 502 and the sell screen / Sales History show an inline Retry while the sale
  stays completed. Outside the shell (a browser tab) the receipt page still opens in a popup and
  calls `window.print()`. The page printed silently is rendered with `embedded => true` (no
  buttons, no `window.print()`, Cairo as `data:` URIs through `receipts/_fonts.blade.php`: a
  `data:` origin cannot fetch the http font files). New print entry points follow the same
  split: ask `inNativeShell()`, never open a window from the desktop path.
- Any print that is not the sell screen's first print after a sale (Sales History, the sale
  detail page, anything new) must send `?copy=1&src=<screen>` to `receipts.print` (browser) or to
  the silent `print-thermal` / `print-a4` route (desktop): that stamps COPY / نسخة on the page and
  writes the `receipt_reprint` audit row server-side, and only for a print that actually went
  out. The copy flag is opt-in by the caller, not enforced by the server.
- Failed pages (`App\Support\ErrorPages`, wired in `bootstrap/app.php`): the desktop window has no
  address bar, so no failed page may be a dead end. Every non-JSON status >= 400 renders the one
  standalone page `resources/views/errors/page.blade.php` (logo, "Error NNN", title, message, Go
  home / Go back; inline CSS, no Vite, no database, so it renders when the app itself broke; copy in
  `lang/*/errors.php`, with a generic fallback for any other status). A message passed to
  `abort(403, '...')` is shown; exception text never is, and `APP_DEBUG` keeps the stack trace for
  unhandled exceptions. A 419 (CSRF token no longer matches its session) on a signed-out request
  redirects to the login page with a notice and a fresh token, a JSON one answers in the user's
  language, and only a signed-in user sees the error page. The login page also reloads itself before
  the session lifetime runs out and when restored from the back/forward cache. Add copy for a new
  status to `errors.codes`; don't add per-status view files.
- Recurring expenses (Expenses > Recurring, behind the `expenses` feature and `role:admin,manager`):
  `recurring_expenses` templates (weekly `day_of_week` 1=Mon..7=Sun, monthly `day_of_month` with the
  month's last day when it is short, yearly = the start date's day and month) are turned into real
  `expenses` rows only by `RecurringExpenseService::generateDue()`. It is idempotent
  (`last_generated_for` cursor + unique `(recurring_expense_id, period_date)` on `expenses`, which
  also holds for soft-deleted rows) and cheap when nothing is due, so it is called from everywhere:
  the daily `Schedule` entry in `routes/console.php`, app launch (`NativeAppServiceProvider`),
  every `Login`, and the Generate now button. Under NativePHP the scheduler loop is opt-in and off
  (`nativephp.scheduler.enabled`), so launch + sign-in are what the desktop build relies on. A
  generated expense is `paid_from = outside` (never the drawer), has `created_by = null` and shows
  an Auto badge; deleting a template soft-deletes it so booked expenses keep their link. Editing
  a template changes future expenses only. Audit: every template create / update / pause / resume /
  delete and one `recurring_expenses_generated` row per batch.

## i18n / RTL

The UI is fully bilingual (`en` / `ar`). Resolution order (`SetLocale`): the
user's `language` -> the session (`/locale/{locale}`, used on the login screen) ->
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
