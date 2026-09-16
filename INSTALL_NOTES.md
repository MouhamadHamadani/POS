# LebaSouk — Windows Install Notes

Applies to the **unsigned Windows x64 build** produced from this repository.
Read the whole page before touching the client's machine — the first-login step
matters, and there is no way to redo it quietly afterwards.

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
| Accounts | **None.** No credential ships in the build; the owner account is created on the machine at first run (§4) |
| Sample data | **None.** Config only — VAT, exchange rate, currencies, receipt settings |
| Database | SQLite, created on first launch at `%APPDATA%\lebasouk\database\database.sqlite` |

> **Rebuild required after the rebrand.** The installer currently sitting in
> `dist/` was built under the old name and still carries the old app id and
> `%APPDATA%` folder. Rebuild (§7) to get the values in this table. Because the
> app id and app name both changed, a rebranded build installs *alongside* an
> old install rather than upgrading it, and starts from an empty database in the
> new `%APPDATA%\lebasouk\` folder — on any machine that already ran the old
> build, copy the old `database.sqlite` across before the client uses it.

---

## 2. Running the installer

1. Copy `LebaSouk-1.0.0-setup.exe` to the machine (USB or download).
2. Double-click it.
3. Work through the SmartScreen warning — see §3.
4. The installer creates a desktop shortcut and a Start Menu entry, both named
   **LebaSouk**.
5. Launch it. The first start runs migrations and lays down config defaults, so
   it takes a few seconds longer than later starts (about 20–30 seconds on a
   cold start). You land on the **setup screen**, not the login page — see §4.
   The packaged build in `dist/win-unpacked` predates both the rebrand and the
   setup screen, so it still shows the old name and the old seeded login until
   you rebuild.

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

## 4. First run — setting up the machine

**The build contains no accounts and no password.** Nothing is seeded, so there
is no shared credential to extract from the installer and no two machines start
out the same. The first launch opens a one-time **setup screen** instead of the
login page — every route redirects there until an account exists.

Do this with the machine in front of you, before the client sits down:

1. Launch the app. You land on **Set up this machine**.
2. Create the **owner account**. This is the `super_admin` — your account, the
   one that can take, download, restore and delete database backups, and the
   only one that can create or edit other admin-tier accounts. Choose the
   password yourself; nothing is pre-filled.
3. The setup screen closes permanently the moment that account is created. It
   returns 404 from then on, so it can never be used to mint a second
   `super_admin`. Further accounts come from **Users → New User**.
4. Go to **Users → New User** and create the client's own account with role
   **Admin**. This is the store-owner account they use day to day. Let the
   client choose its password.
5. Have the client log in as their new `admin` account and confirm they can
   reach Products, Settings, Reports, Customers, Suppliers and Purchase Orders.
6. Create their staff accounts (`manager` / `cashier` / `stock`) — the client's
   `admin` can do this themselves from the same screen.

What the client's `admin` account **cannot** do, by design:

- Reach any backup route (Backup Now, download, restore, delete). The Backup tab
  is not shown to them and the routes return 403 if hit directly.
- Create or edit any account whose role is `admin` or `super_admin`. The role
  dropdown only offers Manager / Cashier / Stock Keeper for them, and the server
  rejects the request even if the form is tampered with.

Do **not** delete or deactivate the `super_admin` account — it is the only way
back into backups, and the app refuses to leave itself without an active
admin-tier user.

> **If you delete every account**, the machine becomes unprovisioned again and
> the setup screen comes back. That is the recovery path if the owner password
> is ever lost, but it is also why the app will not let you remove the last
> admin-tier user from inside the UI.

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
to confirm. `--keep-users` spares the accounts; `--force` skips the prompt.

### If the machine has run an older build before

Setup only appears when the users table is empty. A machine that already ran an
earlier build keeps its existing accounts and goes straight to the login screen —
including, if it ran a build from before this change, the old seeded `admin`
account whose password was published in this file.

**On any machine that ever ran one of those builds, change that account's
password (or delete the account) before handing it over.** Check with the
sidebar: if the account you log in as shows **Admin** rather than **Super
Admin** under its name, it also predates the admin/super-admin split and cannot
reach backups. Either:

- wipe `%APPDATA%\lebasouk\database\database.sqlite` to start clean — this
  destroys all sales history on that machine, so take a copy first, and the app
  will open the setup screen on next launch; or
- promote the account by hand, once, against that same file:
  `UPDATE users SET role = 'super_admin' WHERE username = 'admin';`

A genuinely new client machine has neither problem.


## 5. Where the data lives

- Database: `%APPDATA%\lebasouk\database\database.sqlite` (the folder is the
  slugged app name, not the display name)
- Backups (super_admin only, Settings → Backup): written next to the database.

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

The build runs `npm run build` and `php artisan optimize` itself
(`prebuild` in `config/nativephp.php`), then writes the installer to `dist/`.

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

## 8. Known gaps in this build

- **`APP_DEBUG` is `true` and `APP_ENV` is `local`** in the environment this was
  built from. That means a PHP error shows a full stack trace to whoever is
  standing at the till. Set `APP_DEBUG=false` and `APP_ENV=production` and
  rebuild before this goes live for real.
- Arabic UI translation is not in this build. The locale/RTL plumbing works and
  user-entered Arabic data (product `name_ar`, business name on receipts) shows
  correctly, but the interface chrome is English only.
- The app is unsigned (§3) and does not auto-update (§6).
