<?php

namespace App\Providers;

use App\Models\Setting;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Native\Laravel\Contracts\ProvidesPhpIni;
use Native\Laravel\Facades\GlobalShortcut;
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
            ->title('LebaSouk')
            ->width(1400)
            ->height(900)
            ->minWidth(1280)
            ->minHeight(768)
            ->url($base . '/pos')
            ->resizable(true);

        Menu::make(
            Menu::app()->submenu(
                Menu::label('File')->submenu(
                    Menu::link($base . '/pos', 'New Sale'),
                    Menu::link($base . '/pos?action=hold', 'Hold Sale'),
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
                    Menu::link($base . '/reports', 'Daily Summary'),
                    Menu::link($base . '/reports/shifts/z', 'Z-Report'),
                    Menu::link($base . '/reports/inventory/levels', 'Inventory Report'),
                ),
                Menu::label('Tools')->submenu(
                    Menu::link($base . '/settings/backups', 'Backup Now'),
                    Menu::link($base . '/settings', 'Settings'),
                    Menu::link($base . '/users', 'User Management'),
                ),
                Menu::label('Help')->submenu(
                    Menu::link($base . '/about', 'About'),
                    Menu::link('https://nativephp.com/docs/desktop/2/getting-started/introduction', 'Documentation')
                        ->openInBrowser(),
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

        GlobalShortcut::key('CmdOrCtrl+Shift+P')
            ->event(\App\Events\OpenPos::class)
            ->register();

        GlobalShortcut::key('CmdOrCtrl+Shift+R')
            ->event(\App\Events\OpenReports::class)
            ->register();

        GlobalShortcut::key('CmdOrCtrl+B')
            ->event(\App\Events\BackupNow::class)
            ->register();
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
     */
    private function bootstrapDatabase(): void
    {
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
