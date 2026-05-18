<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Native\Laravel\Contracts\ProvidesPhpIni;
use Native\Laravel\Facades\GlobalShortcut;
use Native\Laravel\Facades\Menu;
use Native\Laravel\Facades\MenuBar;
use Native\Laravel\Facades\Window;
use Native\Laravel\Menu\MenuItem;

class NativeAppServiceProvider implements ProvidesPhpIni
{
    public function boot(): void
    {
        $this->bootstrapDatabase();

        // The bundled PHP server runs on a random port; request() carries the
        // live host:port. APP_URL from .env points at Apache and is wrong here.
        $base = request()->getSchemeAndHttpHost();

        Window::open()
            ->title('POS Pro')
            ->width(1400)
            ->height(900)
            ->minWidth(1280)
            ->minHeight(768)
            ->url($base . '/pos')
            ->resizable(true);

        Menu::new()
            ->appMenu()
            ->submenu('File', Menu::new()
                ->link($base . '/pos', 'New Sale')
                ->link($base . '/pos?action=hold', 'Hold Sale')
                ->link($base . '/shifts/close', 'Close Shift')
                ->separator()
                ->quit('Exit')
            )
            ->submenu('View', Menu::new()
                ->toggleFullscreen()
                ->separator()
                ->item(MenuItem::make('Zoom In')->accelerator('CmdOrCtrl++'))
                ->item(MenuItem::make('Zoom Out')->accelerator('CmdOrCtrl+-'))
            )
            ->submenu('Reports', Menu::new()
                ->link($base . '/reports', 'Daily Summary')
                ->link($base . '/reports/shifts/z', 'Z-Report')
                ->link($base . '/reports/inventory/levels', 'Inventory Report')
            )
            ->submenu('Tools', Menu::new()
                ->link($base . '/settings/backups', 'Backup Now')
                ->link($base . '/settings', 'Settings')
                ->link($base . '/users', 'User Management')
            )
            ->submenu('Help', Menu::new()
                ->link($base . '/about', 'About')
                ->link('https://nativephp.com/docs/desktop/2/getting-started/introduction', 'Documentation')
            )
            ->register();

        MenuBar::create()
            ->onlyShowContextMenu()
            ->withContextMenu(
                MenuItem::make('Open POS')->link($base . '/pos'),
                MenuItem::make('Open Reports')->link($base . '/reports'),
                MenuItem::separator(),
                MenuItem::quit('Quit'),
            );

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
     * %APPDATA%/<app>/database/database.sqlite is empty. Run migrations + seeders
     * once so the user has an admin account and sample data ready to go.
     */
    private function bootstrapDatabase(): void
    {
        try {
            // Always run any pending migrations — this catches schema drift between
            // the project DB and the runtime DB (e.g. when a new migration ships
            // after the runtime DB was first created). `migrate` is idempotent:
            // already-applied migrations are skipped.
            Artisan::call('migrate', ['--force' => true]);

            if (User::query()->count() === 0) {
                Artisan::call('db:seed', ['--force' => true]);
                Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\SampleProductsSeeder', '--force' => true]);
            }
        } catch (\Throwable $e) {
            // Swallow — we don't want a seed failure to prevent the window from opening.
            // The user can run `php artisan native:migrate` and `php artisan db:seed` manually.
            logger()->error('NativePHP DB bootstrap failed: ' . $e->getMessage());
        }
    }
}
