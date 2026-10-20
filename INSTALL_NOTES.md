# LebaSouk — Windows Install Notes

Applies to the **unsigned Windows x64 build** produced from this repository.
Read the whole page before touching the client's machine — the first sign-in in
§4 starts the trial clock, and there is no way to redo that quietly afterwards.

---

## 1. What you are installing

| | |
|---|---|
| Product name | LebaSouk (`APP_NAME`) |
| App id | `com.buildsyntax.lebasouk` |
| Version | 1.0.0 (`NATIVEPHP_APP_VERSION`) |
| Platform | Windows x64 only |
| Installer | `LebaSouk-1.0.0-setup.exe` (NSIS), in `dist/` |
| Code signing | **None.** See §3. |
| Auto-update | **Disabled.** See §6. |
| Accounts | **One:** the provider `super_admin`, built in and created on first launch (§4). No client account ships; the password is a hash in the installer's `.env`, never plain text |
| Build mode | `APP_ENV=production`, `APP_DEBUG=false` |
| Time zone | `Asia/Beirut` — receipts and report day boundaries are local, not UTC |
| Sample data | **None.** Config only — VAT, exchange rate, currencies, receipt settings |
| Database | SQLite, created on first launch at `%APPDATA%\lebasouk\database\database.sqlite` |

> **This is the first client release.** Because the app name and the app id both
> changed since the earlier internal builds (`POS Pro`, `com.buildsyntax.pos`),
> this installer installs *alongside* any older build rather than upgrading it,
> and starts from an empty database in `%APPDATA%\lebasouk\`. On a machine that
> already ran one of those builds, copy the old `database.sqlite` across before
> the client uses it, or uninstall the old app first.

---

## 2. Running the installer

1. Copy `LebaSouk-1.0.0-setup.exe` to the machine (USB or download).
2. Double-click it.
3. Work through the SmartScreen warning — see §3.
4. The installer creates a desktop shortcut and a Start Menu entry, both named
   **LebaSouk**.
5. Launch it. The first start runs migrations and lays down config defaults, so
   it takes a few seconds longer than later starts (about 20–30 seconds on a
   cold start). You land on the normal **login page** — see §4.

---

## 3. The SmartScreen warning (expected)

The build is **not code-signed** — Authenticode signing needs a purchased
certificate, which is a procurement step, not a build step. Windows therefore
shows a blue full-screen dialog on first run:

> **Windows protected your PC**
> Microsoft Defender SmartScreen prevented an unrecognised app from starting.
> Running this app might put your PC at risk.
> *App:* LebaSouk-1.0.0-setup.exe   *Publisher:* Unknown publisher

To continue:

1. Click **More info** (small link under the message text — it is easy to miss).
2. The dialog expands and a **Run anyway** button appears at the bottom.
3. Click **Run anyway**.

If a corporate policy has removed the "Run anyway" button, SmartScreen cannot be
clicked through and the machine's administrator has to allow the file (or the
build has to be signed). Nothing in the app can work around that.

Some antivirus products also quarantine unsigned installers. If the file
disappears after copying, add an exclusion for it before retrying.

Buying an EV/OV code-signing certificate and signing the build removes this
warning permanently. That is the fix; everything above is a workaround.

---

## 4. First run — the built-in provider account

**There is no setup screen.** The build carries one account, the provider
`super_admin`, created automatically on the first launch (and again on any later
launch that finds none). Its username and display name come from
`POS_SUPER_ADMIN_USERNAME` (default `buildsyntax`) and `POS_SUPER_ADMIN_NAME`;
its password was agreed in advance and ships only as a **bcrypt hash** in
`POS_SUPER_ADMIN_PASSWORD_HASH`. The plain password is in neither the repository,
the `.env` nor the installer. The first launch opens on the normal login page.

Generate the hash once, on your own machine, and put it in the `.env` the
installer is built from (single-quoted — it contains `$`):

```bash
php artisan tinker --execute="echo bcrypt('the-password');"
```

`native:build` refuses to package a non-demo build whose `.env` has no valid hash.
The hash does travel inside the installer, so the password behind it must be long
and unique — a weak one can be guessed offline from the hash.

If a machine ever opens on **"This installation is not provisioned"**, the hash
was missing or the username belongs to another account; the reason is in the log
(§5). Fix the `.env`, then run `php artisan pos:ensure-super-admin` or relaunch.

Do this with the machine in front of you, before the client sits down:

1. Launch the app and sign in with the agreed provider username and password.
   This is the `super_admin` — yours, not the store's: the one that can take,
   download, restore and delete database backups, set the plan and activate the
   install (§4a), and the only one that can create or edit other admin-tier
   accounts.
2. **The 14-day trial starts at this first sign-in** (§4a), not when the
   installer was staged. A fresh install is on the **Starter** plan; set the plan
   the machine was sold under **Settings → Plan**. If you change the password
   inside the app, later launches never reset it.
3. Go to **Users → New User** and create the client's own account with role
   **Admin**. This is the store-owner account they use day to day. Let the
   client choose its password.
4. Have the client log in as their new `admin` account and confirm they can
   reach Products, Settings, Reports, Customers, Suppliers and Purchase Orders.
5. Create their staff accounts (`manager` / `cashier` / `stock`) — the client's
   `admin` can do this themselves from the same screen.

What the client's `admin` account **cannot** do, by design:

- Reach any backup route (Backup Now, download, restore, delete). The Backup tab
  is not shown to them and the routes return 403 if hit directly.
- Create or edit any account whose role is `admin` or `super_admin`. The role
  dropdown only offers Manager / Cashier / Stock Keeper for them, and the server
  rejects the request even if the form is tampered with.

Do **not** delete or deactivate the `super_admin` account — it is the only way
back into backups and activation, and the app refuses to leave itself without an
active admin-tier user.

### 4a. The 14-day trial and activation

This is a paid, perpetual-licence build. A **fresh** install runs normally for
14 days from the provider's first sign-in. Unless you have activated it by then, it **locks**:
every screen is replaced by "Your 14-day trial has ended — please contact your
provider" (English and Arabic). Nothing is deleted or hidden at the data level —
the database is untouched and everything is back the instant you activate.

- **One Plan tab, two independent sections.** *Plan / Tier* is which features and
  seat limit the install was sold with; *Activation / Trial* is whether it may
  run at all. Changing one never writes the other, and a locked install is locked
  whatever its tier says.
- **Activate:** sign in as `super_admin` → **Settings → Plan** → enter your own
  password → **Activate permanently**. It is audit-logged. You can do this at any
  point, including while locked: a locked install lets `super_admin` sign in and
  reach that one tab (both sections work there), and nothing else. The Plan tab
  exists for `super_admin` only; the client's `admin` never sees it, and gets a
  403 on both of its routes. The generic Settings form ignores any key it does
  not know, so the plan and activation settings cannot be posted through it.
- **Deactivate (lock now):** the same section has a red button for when a client
  stops paying, or the install was delivered before payment. Enter your password
  and tick the box; the client is locked out at once with "paused by your
  provider" (no mention of a trial). Nothing is deleted, and **Activate
  permanently** brings everything back. Audit-logged; not available in a demo build.
- **Warning:** from day 10 the client's `admin` or `manager` (the highest account
  a Starter install can have — it has no admin role) sees an amber banner telling
  them to contact you. Cashiers and the vendor account do not.
- **Existing installs do not lock.** An install with no activation record — every
  machine already in the field — is treated as activated, and the next update
  writes that down (`activation_status`, `installed_at` and `tier` rows, only
  where missing). Only a fresh install, whose provider account signs in for the
  first time on this version, starts a trial.
- **Date problems.** If the system date is more than a day earlier than the last
  time the app ran, a *trial* install shows the lock screen with a "check the
  date" message until the date is corrected (it is not a permanent lock). If a
  client's clock was ever far wrong at your first sign-in, activate, or the trial
  will read as locked until real time catches up.
- **What it is not.** The status is a setting in the local SQLite file, so anyone
  who opens that file can set it to `activated`. It stops a non-technical client,
  not a determined one. A vendor-signed activation code is the upgrade if that
  ever matters (see the note on `Activation::activate()`).

> **If you delete every account**, the next launch recreates the built-in
> `super_admin` from the `.env` hash, so the provider can always get back in. It
> only does so while no `super_admin` exists; a password you changed inside the
> app is never reset. The app still will not let you remove the last admin-tier
> user from inside the UI.

### The till starts empty

No sample products, categories or customers ship with the build. What the client
gets on first run is configuration only — VAT rate, exchange rate, LBP rounding
step, currencies, receipt settings — so the till prices correctly from the start
but the catalogue is theirs to enter.

To put a machine back into that state (after a demo, say):

```bash
php artisan pos:reset-data
```

It clears the catalogue, all transactional history and every account, keeps
settings/taxes/currencies, and prints what it is about to delete before asking
to confirm. `--keep-users` spares the accounts; `--force` skips the prompt. The
built-in `super_admin` is recreated on the next launch (§4).

### If the machine has run an older build before

The built-in account is only created while no `super_admin` exists. A machine
that already ran an earlier build keeps its existing accounts, passwords and plan
untouched and goes straight to the login screen — including, if it ran a build
from before the admin/super-admin split, the old seeded `admin` account whose
password was published in this file.

**On any machine that ever ran one of those builds, change that account's
password (or delete the account) before handing it over.** Check with the
sidebar: if the account you log in as shows **Admin** rather than **Super
Admin** under its name, it also predates the admin/super-admin split and cannot
reach backups. Either:

- wipe `%APPDATA%\lebasouk\database\database.sqlite` to start clean — this
  destroys all sales history on that machine, so take a copy first, and the app
  will recreate the built-in `super_admin` on next launch; or
- promote the account by hand, once, against that same file:
  `UPDATE users SET role = 'super_admin' WHERE username = 'admin';`

A genuinely new client machine has neither problem.


## 5. Where the data lives

- Database: `%APPDATA%\lebasouk\database\database.sqlite` (the folder is the
  slugged app name, not the display name)
- Backups (super_admin only, Settings → Backup): written next to the database.
- Laravel log: `%APPDATA%\lebasouk\storage\logs\` (daily files, `warning` and
  above — see §8).
- Electron main-process warnings: `%APPDATA%\lebasouk\storage\logs\nativephp-main.log`.
  NativePHP itself only ever writes main-process output to a console nobody
  sees in a packaged build, so this file exists for the one case that has bitten
  a till — see §10. It is written only when something goes wrong; an absent
  file means nothing has.

Backing the machine up is a manual step today: take a backup from
Settings → Backup and copy the file off the machine. There is no scheduled
off-machine backup.

---

## 6. Auto-update: not configured

`NATIVEPHP_UPDATER_ENABLED` is `false` and **no updater provider has real
credentials** — there are no GitHub, S3 or DigitalOcean Spaces values in the
environment. `config/nativephp.php` still lists all three providers with
`spaces` as the nominal default, but nothing is wired to it and nothing is
published on build.

So: **this build will not update itself.** A new version means building a new
installer and running it on the machine again (it upgrades in place; the
database in `%APPDATA%` is left alone).

If auto-update is wanted later, pick one provider, put its credentials in the
environment, set `NATIVEPHP_UPDATER_ENABLED=true`, and build with
`php artisan native:build win x64 --publish`.

---

## 7. Rebuilding

From the repository root, on a **Windows** machine (Windows targets must be
built on Windows):

```bash
php artisan native:build win x64
```

**The build refuses to run against an unsafe `.env`.** Before anything is
packaged, `native:build` checks that `APP_ENV=production`, `APP_DEBUG=false` and
`APP_KEY` is set, and aborts with the list of what is wrong if not:

```
Refusing to build LebaSouk 1.0.0 (local). Fix .env first:
  - APP_ENV is "local", expected "production".
  - APP_DEBUG is true — a PHP error would print a stack trace at the till.
```

§9 used to carry "shipped with APP_DEBUG on" as a known gap; this is why it no
longer can. To check a machine without starting a build:

```bash
php artisan pos:assert-release-env
```

Add `--demo` when you mean to build a demo sandbox — that form also asserts
`POS_DEMO_MODE` matches your intent, which `native:build` cannot do on its own.

Past the guard, the build runs `npm run build` and `php artisan optimize`
itself (`prebuild` in `config/nativephp.php`), then writes the installer to
`dist/`.

Bump `NATIVEPHP_APP_VERSION` before every release build — NativePHP uses the
version change to decide when to run migrations against the installed
database.

Two things that will bite you right after a build:

- `php artisan optimize` (part of `prebuild`) leaves `bootstrap/cache/config.php`
  behind. A cached config overrides the `<env>` block in `phpunit.xml`, so the
  test suite stops using the in-memory SQLite database and fails with
  "database is locked". Run `php artisan optimize:clear` (or delete
  `bootstrap/cache/{config,routes-v7,events}.php`) before running tests again.
- `npm ci` in `vendor/nativephp/electron/resources/js` fails with `EBUSY` if a
  `native:serve` dev instance is still running — it holds
  `node_modules/electron/dist`. Close the dev app before building.

---

## 8. What changed for this release

- `APP_ENV=production`, `APP_DEBUG=false`. A PHP error now shows a plain error
  page instead of a full stack trace to whoever is standing at the till.
- `APP_TIMEZONE=Asia/Beirut`. Receipt times and the day boundary on daily and
  shift reports are local Lebanese time. Earlier builds stamped everything UTC,
  so a sale after midnight local time could land on the previous day's report.
- Logs rotate daily and keep 14 days (`LOG_STACK=daily`, `LOG_DAILY_DAYS=14`) at
  `warning` and above, instead of a single `laravel.log` growing forever at
  `debug` on a machine nobody ever clears.
- The desktop menu bar's dead entries are fixed. Reports → Inventory Report,
  Tools → Backup Now and Help → About all pointed at routes that do not exist,
  and landed the till on a 404.
- The three global keyboard shortcuts are removed. They fired events that had no
  listener, so they did nothing — and a *global* shortcut is registered OS-wide,
  so `Ctrl+B` was swallowed from every other application on the machine for as
  long as the till was running.
- `NATIVEPHP_APP_ID` now matches this document (`com.buildsyntax.lebasouk`); the
  environment still carried `com.buildsyntax.pos` from before the rebrand.
- The auto-updater now defaults to **off** in `config/nativephp.php` rather than
  on, so a missing environment variable cannot switch on an updater that has no
  provider behind it (§6).
- `native:build` now refuses to package an `APP_DEBUG=true` build at all (§7).

## 9. Known gaps in this build

- Arabic UI translation is not in this build. The locale/RTL plumbing works and
  user-entered Arabic data (product `name_ar`, business name on receipts) shows
  correctly, but the interface chrome is English only.
- The app is unsigned (§3) and does not auto-update (§6).
- **Spreadsheet exports come out as `.csv`, not `.xlsx`.** The PHP runtime that
  NativePHP bundles has no `ext-xmlwriter`, so PhpSpreadsheet cannot write an
  `.xlsx` there at all. Exports step down to CSV, which opens in Excel
  regardless, and the export buttons read **Export CSV** in the packaged app so
  nothing is promised that is not delivered. Reading `.xlsx` is unaffected —
  bulk product upload still accepts `.xlsx` and `.xls` files. PDF is unaffected.
- Off-machine backup is manual (§5). Nothing copies the database off the till on
  a schedule.

---

## 10. The background scheduler (off, deliberately)

NativePHP's Electron process runs `artisan schedule:run` on a 60-second timer
from the moment the app launches. It does that whether or not the app has
anything scheduled — and this one does not. Every tick spawned the bundled
`php.exe`, did nothing, and exited.

That is how a client's till died: an antivirus had quarantined the bundled
`php.exe` as a false positive, the next tick failed with `spawn UNKNOWN`, and
because nothing caught it the error reached Electron's main process uncaught
and took the whole app down mid-shift.

Two changes, both on by default in this build:

- **The loop is off.** `NATIVEPHP_SCHEDULER_ENABLED=false` (read as
  `nativephp.scheduler.enabled` in `config/nativephp.php`). No timer, no spawn,
  nothing for an antivirus to interrupt.
- **A failed spawn is survivable.** If the loop is ever turned on and PHP can't
  be started, the tick is skipped, a line is written to
  `storage\logs\nativephp-main.log` (§5), and the next tick tries again. The app
  keeps running.

NativePHP ships no switch of its own — [NativePHP/desktop#147](https://github.com/NativePHP/desktop/issues/147)
is open and unfixed — so both changes are a patch applied to the vendored
Electron plugin by `php artisan nativephp:patch-scheduler`. It runs
automatically after `composer install`/`composer update` and again at the start
of every `native:build`, which **refuses to package** if the patch no longer
applies. Run `php artisan nativephp:patch-scheduler --check` to see where a
working copy stands.

Turning the loop back on is one env var — but do it in the same change that adds
the first real scheduled task. The scheduled off-machine backup named in §9 is
the obvious first customer; the Settings → Backup frequency setting is stored
today but nothing reads it yet.
