# LebaSouk

Offline-first point of sale for Lebanese retail, by **Build Syntax**.

A Laravel 11 application packaged as a Windows desktop app with
[NativePHP](https://nativephp.com/) (Electron). It runs entirely on the till
machine against a local SQLite database — no internet connection required
during a shift.

## What it does

- **Dual currency** — USD is the stored base currency; LBP is derived at the
  configured exchange rate and rounded to the configured step. All conversion
  lives in `App\Services\CurrencyService`.
- **11% Lebanese VAT**, inclusive or exclusive per configuration. Tax rates are
  stored as `0..1` decimals.
- **Bilingual (English / Arabic)** with RTL layout. Model display fields follow
  the `name` / `name_ar` pattern with a `localName()` accessor.
- **Thermal receipt printing** via ESC/POS, plus PDF reports through dompdf.
- **Barcode** scanning and label generation.
- **Role-based access** — `admin`, `manager`, `cashier`, `stock`, enforced
  server-side by the `role:` route middleware.
- **Audit logging** on every mutating action via `AuditLog::record()`.

## Stack

Laravel 11 · PHP 8.2+ · SQLite · NativePHP v2 (Electron) · Blade + Alpine.js +
Tailwind CSS + Vite · Pest.

## Local development

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm run dev
php artisan serve
```

To run it as the desktop app instead of in a browser:

```bash
php artisan native:serve
```

## Tests

```bash
php artisan test
```

If the suite fails with "database is locked", clear a cached config left behind
by a build: `php artisan optimize:clear`.

## Building the desktop app

Windows targets must be built on Windows. Bump `NATIVEPHP_APP_VERSION` first —
NativePHP uses the version change to decide when to run migrations against the
installed database.

```bash
php artisan native:build win x64
```

The installer lands in `dist/`. Builds are unsigned; see
[INSTALL_NOTES.md](INSTALL_NOTES.md) for the SmartScreen workaround, the
first-login procedure, and where data lives on the client machine.

## Contributing notes

Project conventions that are easy to get wrong are written down in
[CLAUDE.md](CLAUDE.md) — read it before adding features. The short version:
settings go through `Setting::get()`/`set()`, money goes through
`CurrencyService`, mutations call `AuditLog::record()`, and business logic
lives in `app/Services/`, not in controllers.
