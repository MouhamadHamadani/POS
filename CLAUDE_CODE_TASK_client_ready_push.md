# POS — Client-Ready Push (Arabic i18n · Windows Packaging · Stability)

## Context

This is a Laravel 11 + NativePHP (Electron) desktop POS app for Lebanese
retail (dual USD/LBP currency, 11% VAT, four roles: admin/manager/cashier/
stock, offline-first via SQLite). The app already covers the full retail
workflow (sell screen, shifts, returns, purchasing, customers, reporting,
backups) and has a partial Pest test suite. A client needs a working build
**by tomorrow morning**. `CLAUDE.md` at the repo root has been updated to
reflect that this repo is now under active development (it is *not*
read-only anymore — ignore any older notes to the contrary) and documents
the codebase's core conventions (CurrencyService, AuditLog::record(),
Setting::get()/set(), bilingual `name`/`name_ar` fields, role middleware).
Read it before starting.

Target platform for this push: **Windows only**. Code signing is explicitly
**out of scope tonight** — a purchased certificate is a human/procurement
step, not something to script around. Ship an unsigned build; the client
will see a SmartScreen warning on first run, which is acceptable for now.

Work the three phases below **in order**. If you run out of time, a fully
finished Phase 1 + Phase 2, with Phase 3 partially done, is much better than
all three half-finished. Run `php artisan test` (or `vendor/bin/pest`) after
each phase and do not move to the next phase with failing tests.

---

## Phase 1 — Arabic UI translation (highest priority)

**Problem:** RTL plumbing already exists — `App\Http\Middleware\SetLocale`,
a per-user `language` field (`en`/`ar`), and the main layouts
(`resources/views/layouts/app.blade.php`, `layouts/pos.blade.php`,
`receipts/thermal.blade.php`) already set `dir="rtl"` and load the Cairo
Arabic font when locale is `ar`. But there is **no `lang/en` or `lang/ar`
directory in this repo at all** — every UI string (buttons, labels,
headings, table columns, empty states, validation messages, flash messages)
is hardcoded English text directly in the Blade views. Only user-entered
bilingual *data* (product `name_ar`, business `name_ar` on receipts)
actually shows Arabic today.

**Do:**

1. Create `lang/en/*.php` and `lang/ar/*.php` translation files, grouped by
   area to match the existing route/controller structure, e.g.:
   `pos.php`, `products.php`, `categories.php`, `customers.php`,
   `suppliers.php`, `purchases.php`, `returns.php`, `reports.php`,
   `settings.php`, `users.php`, `shifts.php`, `auth.php`, `common.php`
   (shared strings: Save, Cancel, Delete, Edit, Search, etc.).
2. Go through every file under `resources/views/**/*.blade.php` and replace
   hardcoded UI text with `__('group.key')` calls, pulling English strings
   from the codebase (not guessing) so `lang/en/*.php` is a faithful
   extraction of what's there today.
3. Write real, natural Arabic translations for `lang/ar/*.php` — proper
   retail/POS terminology (e.g. "Hold Sale" → حفظ الفاتورة مؤقتًا,
   "Shift" → وردية, "Return" → إرجاع), not literal/machine-garbled phrasing.
   Flag any string you're genuinely unsure how to translate rather than
   guessing silently — list them in your final report.
4. Validation messages and flash/session messages (in controllers and Form
   Requests, e.g. `StoreProductRequest`) need the same treatment — Laravel's
   `validation.php` / custom messages should also respect locale.
5. Watch for RTL layout breakage once real Arabic text flows in: numbers
   (LBP/USD amounts, quantities, dates) should stay left-to-right even
   inside an RTL page — wrap them in `<span dir="ltr">…</span>` where
   needed, particularly in `pos/sell.blade.php`, `receipts/thermal.blade.php`,
   and the report views/PDFs.
6. Don't touch the existing `name`/`name_ar` bilingual data-field pattern —
   that's separate from this UI-copy work and already functions correctly.

**Acceptance criteria:**
- Switching a user's `language` to `ar` and clicking through every screen
  (POS sell, products, categories, customers, suppliers, purchase orders,
  returns, reports, settings, users, shifts, auth) shows Arabic UI copy with
  no missing-translation-key artifacts (no raw `group.key` strings visible)
  and no broken RTL layout (misaligned icons, numbers reading backwards,
  overlapping text).
- English (`en`) behaves exactly as before — this is a refactor of how
  strings are stored, not a copy change for English users.
- Existing tests still pass.

---

## Phase 2 — Windows packaging (unsigned build)

**Do:**

1. Confirm the exact NativePHP build/package command for this installed
   version rather than assuming — run `php artisan list` and look for
   `native:*` commands (e.g. `native:build`), or check
   `vendor/nativephp/electron`'s own docs/config. Do not guess syntax.
2. Confirm `config/nativephp.php` / `.env` values are sensible for a client
   build: `NATIVEPHP_APP_VERSION`, `NATIVEPHP_APP_ID`, `APP_NAME` (current
   placeholder is "POS Pro" — leave as-is unless told otherwise; naming is
   a separate, non-blocking decision).
3. Explicitly leave all code-signing / certificate env vars empty — do not
   attempt to self-sign or fabricate a certificate. Confirm the build
   completes and produces a runnable Windows installer/executable without
   a signing step blocking it.
4. Check which auto-updater provider (`github` / `s3` / `spaces`, in
   `config/nativephp.php`) actually has real credentials in `.env`. If none
   are configured, don't leave a silently-broken update flow — note this
   clearly in your report so it can be decided later, and don't spend time
   setting up a channel that wasn't asked for.
5. Run `npm run build` before packaging so Vite assets are current.
6. Write a short, plain-language install note (a markdown or text file is
   fine, e.g. `INSTALL_NOTES.md`) for the client: how to run the installer,
   what the Windows SmartScreen "unrecognized app" warning looks like and
   how to click through it ("More info" → "Run anyway"), and the default
   admin login created by the first-run seeder (check
   `NativeAppServiceProvider::bootstrapDatabase()` / the relevant seeder for
   what that actually is — report it, don't invent credentials).

**Acceptance criteria:**
- A Windows build artifact exists, was produced by the actual documented
  NativePHP command, and you've confirmed (or clearly flagged if you
  couldn't, e.g. no Windows environment available to test-run the .exe)
  that the app launches and reaches the login/POS screen.
- `INSTALL_NOTES.md` exists and is accurate to what you actually did.

---

## Phase 3 — Stability pass (risk reduction, not full coverage)

Given the time constraint, this phase is about not regressing what already
works and de-risking the highest-value gaps — not achieving full coverage.

**Do, in this order:**

1. Run the full existing Pest suite. Fix any failures — especially ones
   Phase 1's `__()` refactor could plausibly have introduced (string
   comparisons in tests that now expect a translation key's resolved text).
2. Manually trace (or write a quick feature test for) the core money-path
   flows end to end, since these are what the client will actually use
   tomorrow: open shift → sell (cash and card if supported) → print receipt
   → close shift with cash reconciliation; and a return against a real sale.
3. Add feature tests for the three highest-risk *currently untested* areas,
   in priority order, time permitting:
   - Shift close cash reconciliation (`ShiftController`, denominations)
   - Return approval/rejection flow (`ReturnController`)
   - `CurrencyService` LBP rounding edge cases (e.g. amounts that round to
     the same LBP bucket, zero/negative guards)
4. Do **not** attempt full coverage of purchases/suppliers, reports, or
   settings/backups tonight — note these as explicit follow-up items
   instead of rushing shallow tests for them.

**Acceptance criteria:**
- `php artisan test` passes fully.
- The core sell → receipt → shift-close and return flows have been
  verified to work with Arabic strings in place (from Phase 1) without
  layout or logic regressions.

---

## Explicitly out of scope tonight

- Code signing / notarization (needs a purchased certificate or Apple
  Developer enrollment — a human procurement step)
- Full test coverage across every module
- Final product naming decision (still "POS Pro")
- Marketing website work
- Mac packaging

## Report back with

- Every lang key file you created/touched, and a list of any strings you
  weren't confident translating (so a native speaker can proof them before
  the client sees the app).
- The exact build command used and where the Windows artifact ended up.
- Which auto-update provider (if any) is actually configured with real
  credentials.
- Full test output, before and after your changes.
- Anything from the three phases you had to skip or partially do, and why.
