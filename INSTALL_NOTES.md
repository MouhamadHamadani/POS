# POS Pro — Windows Install Notes

Applies to the **unsigned Windows x64 build** produced from this repository.
Read the whole page before touching the client's machine — the first-login step
matters, and there is no way to redo it quietly afterwards.

---

## 1. What you are installing

| | |
|---|---|
| Product name | POS Pro (`APP_NAME`; final naming not yet decided) |
| App id | `com.buildsyntax.pos` |
| Version | 1.0.0 (`NATIVEPHP_APP_VERSION`) |
| Platform | Windows x64 only |
| Installer | `POS Pro-1.0.0-setup.exe` (NSIS), in `dist/` |
| Code signing | **None.** See §3. |
| Auto-update | **Disabled.** See §6. |
| Database | SQLite, created on first launch at `%APPDATA%\pos-pro\database\database.sqlite` |

---

## 2. Running the installer

1. Copy `POS Pro-1.0.0-setup.exe` to the machine (USB or download).
2. Double-click it.
3. Work through the SmartScreen warning — see §3.
4. The installer creates a desktop shortcut and a Start Menu entry, both named
   **POS Pro**.
5. Launch it. The first start runs migrations and seeders, so it takes a few
   seconds longer than later starts (about 20–30 seconds on a cold start).
   You should land on the login screen — username and password fields under a
   window titled **POS Pro**. This was verified against the packaged build
   from `dist/win-unpacked`.

---

## 3. The SmartScreen warning (expected)

The build is **not code-signed** — Authenticode signing needs a purchased
certificate, which is a procurement step, not a build step. Windows therefore
shows a blue full-screen dialog on first run:

> **Windows protected your PC**
> Microsoft Defender SmartScreen prevented an unrecognised app from starting.
> Running this app might put your PC at risk.
> *App:* POS Pro-1.0.0-setup.exe   *Publisher:* Unknown publisher

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

## 4. First login — do this before handing the machine over

The installer seeds exactly **one** account, and it is the **vendor/owner**
account, not the client's:

| | |
|---|---|
| Username | `admin` |
| Password | `admin123` |
| PIN | `1234` |
| Role | `super_admin` |

**`super_admin` is our account, not the client's.** It is the only role that can
take, download, restore and delete database backups, and the only role that can
create or edit other admin-tier accounts.

Do this, in order, before the client sits down:

1. Log in as `admin` / `admin123`.
2. **Change that password immediately** (Profile → password), and set a new PIN.
   The seeded credentials are public knowledge — they are in this file.
3. Go to **Users → New User** and create the client's own account with role
   **Admin**. This is the store-owner account they will use day to day.
   Give it a password the client chooses.
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

Everything else an admin could do before the two-tier split, they can still do.

Do **not** delete or deactivate the `super_admin` account — it is the only way
back into backups, and the app refuses to leave itself without an active
admin-tier user.

### If the machine has run an older build before

Seeding only happens when the users table is empty. A machine that already ran
a pre-`super_admin` build keeps its existing `admin`-role account and gets
**no** `super_admin` — so nobody on that machine can reach backups.

Check with the login screen: if the account you log in as shows **Admin** (not
**Super Admin**) under its name in the sidebar, you are in this case. Either:

- wipe `%APPDATA%\pos-pro\database\database.sqlite` to start clean (this
  destroys all sales history on that machine — take a copy first), or
- promote the account by hand, once, against that same file:
  `UPDATE users SET role = 'super_admin' WHERE username = 'admin';`

A genuinely new client machine has neither problem — verified: launching the
packaged build against an empty data directory seeds exactly one account,
`admin` / `System Owner` / `super_admin`.

---

## 5. Where the data lives

- Database: `%APPDATA%\pos-pro\database\database.sqlite` (the folder is the
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
