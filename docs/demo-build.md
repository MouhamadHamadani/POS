# Building the LebaSouk demo

One codebase, two build profiles. The demo is the production app with
`POS_DEMO_MODE=true`; everything else — pricing, VAT, USD/LBP conversion,
bilingual UI, barcode scanning, role gates, receipt printing — behaves exactly
as it does for a paying client, because demonstrating that is the point.

## What the flag changes

| Area | Production | Demo (`POS_DEMO_MODE=true`) |
|---|---|---|
| Database | `%APPDATA%\LebaSouk\database\database.sqlite`, persistent | `%APPDATA%\LebaSouk Demo\database\database.sqlite`, **replaced from `database/demo-template.sqlite` on every launch** |
| Accounts | none shipped; owner created once at `/setup` | `demo_admin` / `demo_manager` / `demo_cashier` / `demo_stock`, shared password, listed on the login screen |
| `super_admin` | created by `/setup` | never created — `/setup` is closed because accounts already exist |
| Banner | none | amber "DEMO MODE" strip, bilingual, on every screen |
| Window / app title | `LebaSouk` | `LebaSouk (Demo)`, installed as `LebaSouk Demo` |
| Receipts | normal | printable, with a bilingual `DEMO RECEIPT — NOT A VALID RECEIPT` block top and bottom |
| Backups | `super_admin` only | refused (`demo:blocked`) |
| Report exports (PDF/XLSX) | available | refused — reports stay viewable on screen |
| Settings: business identity, currency, VAT | editable by admin | hidden and refused server-side |
| Auto-updater | per `NATIVEPHP_UPDATER_ENABLED` | off — nothing leaves a demo machine |
| `Reset Demo Data` action | absent (route 404s) | in the sidebar and the till's user menu |

## Build a demo (Windows)

Build Windows targets **on** Windows — `native:build win` produces the NSIS
installer.

```bash
cp .env.demo.example .env && php artisan key:generate
```

```bash
php artisan native:build win
```

`config/nativephp.php` runs `php artisan demo:build-template` as a prebuild step
whenever `POS_DEMO_MODE` is set, so the packaged app always carries a freshly
seeded template. The template is **not** committed — `database/.gitignore`
excludes `*.sqlite*` — and `DemoSeeder` is its only source of truth.

Bump `NATIVEPHP_APP_VERSION` (or `config/nativephp.php` `version`) before each
build as usual; migrations run on version change.

To regenerate the template by hand, e.g. after editing the demo catalogue:

```bash
php artisan demo:build-template
```

## Build production

```bash
cp .env.example .env && php artisan key:generate
```

Leave `POS_DEMO_MODE` unset (or `false`), keep `APP_NAME=LebaSouk` and
`NATIVEPHP_APP_ID=com.buildsyntax.lebasouk`, then build as usual. Nothing in the
demo work changes a production build: every demo branch is behind
`App\Support\Demo::enabled()`, which is `false` by default.

## Run a demo locally (no packaging)

The path guard requires the word `demo` in the resolved database path, so point
the connection at a demo file:

```bash
cp database/demo-template.sqlite database/demo.sqlite
```

```bash
POS_DEMO_MODE=true DB_DATABASE=database/demo.sqlite php artisan serve
```

`php artisan serve` has no NativePHP launch hook, so the per-launch restore does
not fire — use the in-app **Reset Demo Data** button, or re-copy the template.

## Why it can't touch a real database

Two independent guards, both in `App\Support\Demo`:

1. **Path guard** — `guardDatabasePath()` runs from `AppServiceProvider::boot()`
   on every request (after NativePHP has rewritten the connection to the
   packaged app's data directory) and again inside the reset itself. If a demo
   build resolves to a database path without `demo` in it — someone pointing a
   demo at `%APPDATA%\LebaSouk\` — it refuses to boot instead of overwriting it.
2. **Separate install** — `APP_NAME="LebaSouk Demo"` and
   `NATIVEPHP_APP_ID=com.buildsyntax.lebasouk.demo` give the demo its own
   Electron `userData` directory, its own installer identity, and its own
   Start Menu entry, so a demo and a production install coexist on one machine
   without either clobbering the other.

## Where the reset happens

`NativeAppServiceProvider::boot()` — the earliest per-launch hook the package
exposes. Electron POSTs `/_native/api/booted` on startup and
`NativeAppBootedController` resolves `config('nativephp.provider')` and calls
`boot()` on it (`vendor/nativephp/laravel/src/Http/Controllers/NativeAppBootedController.php`).
That runs before `Window::open()`, so the swap lands before the till is on
screen. The restore is deliberately outside the `try/catch` that wraps
migrations: a failed migration should not stop the window opening, a failed demo
reset should stop everything, because the alternative is demoing on the last
meeting's data.

The mid-meeting button (`POST /demo/reset`, `demo:only`) runs the same
`Demo::resetFromTemplate()`: confirm dialog → drop the connection → delete the
WAL sidecars → copy the template over the live file (short retry for a Windows
file lock) → flush the settings cache → log the actor out.

## Still needs verifying on real hardware

Nothing below can be settled by code review or the test suite:

- The boot-time file copy inside the packaged Electron build — file-lock
  semantics differ from `php artisan serve`, and NativePHP keeps queue workers
  polling the same SQLite file.
- A real barcode scanner against the seeded barcodes (`2900000000018` …). They
  are valid EAN-13 in the `29` in-store range; the test suite checks the check
  digits, not a scanner.
- Physical receipt output — confirm the watermark block doesn't wrap badly on
  58 mm paper (it is three short lines for that reason; 80 mm is roomy).
- A demo build and a production build installed side by side on one Windows
  machine, confirming neither installer touches the other's data directory.
