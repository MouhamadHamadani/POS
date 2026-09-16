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
- Four roles: `admin`, `manager`, `cashier`, `stock`, enforced via the
  `role:` route middleware (`App\Http\Middleware\RoleMiddleware`).
- VAT and other tax rates are stored as `0..1` decimals, not percentages.
- Business logic lives in `app/Services/*`, not in controllers.

## i18n / RTL — current gap

- `App\Http\Middleware\SetLocale` and a per-user `language` (`en`/`ar`) field
  already drive `app()->getLocale()`, and the main layouts already set
  `dir="rtl"` and load the Cairo Arabic font when locale is `ar`.
- What's missing: there is **no `lang/en` or `lang/ar` translation catalog**
  in this repo. All UI copy (buttons, labels, headings) is hardcoded English
  text in the Blade views, not run through `__()`. Only user-entered
  bilingual *data* (product `name_ar`, business `name_ar` on receipts)
  actually renders in Arabic today.
- When wiring up real Arabic support: extract hardcoded strings into
  `lang/en/*.php` / `lang/ar/*.php` files (group by area, e.g. `pos.php`,
  `products.php`, `settings.php`), swap Blade text for `__('group.key')`,
  and verify RTL layout doesn't break (icons, alignment, number formatting
  for LBP amounts should stay LTR even in an RTL page).

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
