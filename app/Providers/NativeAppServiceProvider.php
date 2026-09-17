<?php

namespace App\Providers;

use App\Models\Setting;
use App\Support\Demo;
use Illuminate\Support\Facades\Artisan;
use Native\Laravel\Contracts\ProvidesPhpIni;
use Native\Laravel\Facades\Menu;
use Native\Laravel\Facades\MenuBar;
use Native\Laravel\Facades\Window;

class NativeAppServiceProvider implements ProvidesPhpIni
{
    public function boot(): void
    {
        $this->bootstrapDatabase();

        // The bundled PHP server runs on a random port; request() carries the
        // live host:port. APP_URL from .env points at Apache and is wrong here.
        $base = request()->getSchemeAndHttpHost();

        Window::open()
            ->title(config('app.name').Demo::titleSuffix())
            ->width(1400)
            ->height(900)
            ->minWidth(1280)
            ->minHeight(768)
            ->url($base . '/pos')
            ->resizable(true);

        // Every link below is a real route in routes/web.php. A menu entry
        // pointing at a path that does not exist lands the till on a 404 —
        // and with APP_DEBUG=false that is a blank error page, not a clue.
        Menu::make(
            Menu::app()->submenu(
                Menu::label('File')->submenu(
                    Menu::link($base . '/pos', 'New Sale'),
                    Menu::link($base . '/shifts/open', 'Open Shift'),
                    Menu::link($base . '/shifts/close', 'Close Shift'),
                    Menu::separator(),
                    Menu::quit('Exit'),
                ),
                Menu::label('View')->submenu(
                    Menu::fullscreen(),
                    Menu::separator(),
                    Menu::label('Zoom In')->accelerator('CmdOrCtrl++'),
                    Menu::label('Zoom Out')->accelerator('CmdOrCtrl+-'),
                ),
                Menu::label('Reports')->submenu(
                    Menu::link($base . '/reports/sales/daily', 'Daily Sales Summary'),
                    Menu::link($base . '/reports/sales/by-product', 'Sales by Product'),
                    Menu::link($base . '/reports/inventory/stock-levels', 'Inventory Stock Levels'),
                    Menu::link($base . '/reports/financial/pnl', 'Profit & Loss'),
                    Menu::separator(),
                    Menu::link($base . '/reports', 'All Reports'),
                ),
                Menu::label('Tools')->submenu(
                    // The Backup tab, not a backup: taking one is a POST, and a
                    // menu item cannot make one. Super-admin only; anyone else
                    // gets the General tab (SettingController::index).
                    Menu::link($base . '/settings?tab=backup', 'Backups'),
                    Menu::link($base . '/settings', 'Settings'),
                    Menu::link($base . '/users', 'User Management'),
                ),
                Menu::label('Help')->submenu(
                    Menu::link($base . '/about', 'About'),
                ),
            ),
        )
            ->register();

        MenuBar::create()
            ->onlyShowContextMenu()
            ->withContextMenu(Menu::make(
                Menu::link($base . '/pos', 'Open POS'),
                Menu::link($base . '/reports', 'Open Reports'),
                Menu::separator(),
                Menu::quit('Quit'),
            ));

        // No GlobalShortcut registrations. The three that used to live here
        // (Ctrl+Shift+P, Ctrl+Shift+R, Ctrl+B) dispatched event classes that
        // had no listener, so they did nothing — while a *global* shortcut is
        // registered OS-wide, so Ctrl+B was swallowed from every other
        // application for as long as the till was running. Re-add them only
        // together with listeners that actually navigate the window.
    }

    public function phpIni(): array
    {
        return [
            'memory_limit' => '512M',
            'max_execution_time' => '120',
            'display_errors' => '0',
        ];
    }

    /**
     * On first launch (and after a fresh NativePHP install), the runtime DB at
     * %APPDATA%/<app>/database/database.sqlite is empty. Bring the schema up to
     * date and lay down config defaults.
     *
     * Deliberately seeds no user and no sample catalogue: a shipped build must
     * not carry a known credential, and a client's till must not open on
     * somebody's demo products. The owner account is created on the machine
     * through /setup.
     *
     * A demo build instead restores the seeded template over the runtime DB
     * first, so every launch starts from the same catalogue no matter what the
     * last meeting did to it.
     */
    private function bootstrapDatabase(): void
    {
        // This provider's boot() is the earliest per-launch hook the package
        // exposes: Electron POSTs /_native/api/booted on startup and
        // NativeAppBootedController resolves config('nativephp.provider') and
        // calls boot() on it (vendor/nativephp/laravel/src/Http/Controllers/
        // NativeAppBootedController.php). It runs before Window::open() above,
        // so the swap lands before the till is on screen.
        //
        // Outside the try/catch on purpose. A migrate failure should not stop
        // the window opening; a failed demo reset should stop everything,
        // because the alternative is demoing on last meeting's data.
        if (Demo::enabled()) {
            Demo::resetFromTemplate();
        }

        try {
            // Always run any pending migrations — this catches schema drift between
            // the project DB and the runtime DB (e.g. when a new migration ships
            // after the runtime DB was first created). `migrate` is idempotent:
            // already-applied migrations are skipped.
            Artisan::call('migrate', ['--force' => true]);

            // Only into a database that has no config yet. DefaultSettingsSeeder
            // is updateOrCreate, so re-running it on every launch would silently
            // reset a client's configured exchange rate and VAT settings.
            if (Setting::query()->doesntExist()) {
                Artisan::call('db:seed', ['--force' => true]);
            }
        } catch (\Throwable $e) {
            // Swallow — we don't want a seed failure to prevent the window from opening.
            // The user can run `php artisan native:migrate` and `php artisan db:seed` manually.
            logger()->error('NativePHP DB bootstrap failed: ' . $e->getMessage());
        }
    }
}
