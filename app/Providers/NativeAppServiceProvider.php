<?php

namespace App\Providers;

use App\Models\Setting;
use App\Support\Demo;
use App\Support\SuperAdmin;
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

        // The menu is built once at boot, before any user is known, so the store
        // default is the only sensible language. It follows a change in Settings
        // after the next restart.
        $lang = $this->menuLocale();
        $t = fn (string $key) => __("native.$key", [], $lang);

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
                Menu::label($t('file'))->submenu(
                    Menu::link($base . '/pos', $t('new_sale')),
                    Menu::link($base . '/shifts/open', $t('open_shift')),
                    Menu::link($base . '/shifts/close', $t('close_shift')),
                    Menu::separator(),
                    Menu::quit($t('exit')),
                ),
                Menu::label($t('view'))->submenu(
                    Menu::fullscreen(),
                    Menu::separator(),
                    Menu::label($t('zoom_in'))->accelerator('CmdOrCtrl++'),
                    Menu::label($t('zoom_out'))->accelerator('CmdOrCtrl+-'),
                ),
                Menu::label($t('reports'))->submenu(
                    Menu::link($base . '/reports/sales/daily', __('reports.titles.daily_sales', [], $lang)),
                    Menu::link($base . '/reports/sales/by-product', __('reports.titles.sales_by_product', [], $lang)),
                    Menu::link($base . '/reports/inventory/stock-levels', __('reports.titles.stock_levels', [], $lang)),
                    Menu::link($base . '/reports/financial/pnl', __('reports.titles.pnl', [], $lang)),
                    Menu::separator(),
                    Menu::link($base . '/reports', $t('all_reports')),
                ),
                Menu::label($t('tools'))->submenu(
                    // The Backup tab, not a backup: taking one is a POST, and a
                    // menu item cannot make one. Super-admin only; anyone else
                    // gets the General tab (SettingController::index).
                    Menu::link($base . '/settings?tab=backup', $t('backups')),
                    Menu::link($base . '/settings', $t('settings')),
                    Menu::link($base . '/users', $t('user_management')),
                ),
                Menu::label($t('help'))->submenu(
                    Menu::link($base . '/about', $t('about')),
                ),
            ),
        )
            ->register();

        MenuBar::create()
            ->onlyShowContextMenu()
            ->withContextMenu(Menu::make(
                Menu::link($base . '/pos', $t('open_pos')),
                Menu::link($base . '/reports', $t('open_reports')),
                Menu::separator(),
                Menu::quit($t('quit')),
            ));

        // No GlobalShortcut registrations. The three that used to live here
        // (Ctrl+Shift+P, Ctrl+Shift+R, Ctrl+B) dispatched event classes that
        // had no listener, so they did nothing — while a *global* shortcut is
        // registered OS-wide, so Ctrl+B was swallowed from every other
        // application for as long as the till was running. Re-add them only
        // together with listeners that actually navigate the window.
    }

    /** The store's default language, or en if settings are unreadable this early. */
    private function menuLocale(): string
    {
        try {
            $lang = (string) Setting::get('language', 'en');
        } catch (\Throwable) {
            $lang = 'en';
        }

        return in_array($lang, ['en', 'ar'], true) ? $lang : 'en';
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
     * Seeds no sample catalogue (a client's till must not open on somebody's
     * demo products) and no client account. The one account it creates is the
     * vendor's built-in super_admin, from a bcrypt hash in POS_SUPER_ADMIN_*
     * (see App\Support\SuperAdmin) — so a fresh install opens on the login page.
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

            // Outside that condition on purpose: `pos:reset-data` wipes the users
            // but keeps the settings, so this is what brings the account back. A
            // no-op whenever a super_admin already exists, and in demo builds.
            SuperAdmin::ensure();
        } catch (\Throwable $e) {
            // Swallow — we don't want a seed failure to prevent the window from opening.
            // The user can run `php artisan native:migrate` and `php artisan db:seed` manually.
            logger()->error('NativePHP DB bootstrap failed: ' . $e->getMessage());
        }
    }
}
