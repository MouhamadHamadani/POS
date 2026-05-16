<?php

namespace App\Providers;

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
        Window::open()
            ->title('POS Pro')
            ->width(1400)
            ->height(900)
            ->minWidth(1280)
            ->minHeight(768)
            ->url(config('app.url') . '/pos')
            ->resizable(true);

        Menu::new()
            ->appMenu()
            ->submenu('File', Menu::new()
                ->link(url('/pos'), 'New Sale')
                ->link(url('/pos?action=hold'), 'Hold Sale')
                ->link(url('/shifts/close'), 'Close Shift')
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
                ->link(url('/reports/sales/daily'), 'Daily Summary')
                ->link(url('/reports/shifts/z'), 'Z-Report')
                ->link(url('/reports/inventory/levels'), 'Inventory Report')
            )
            ->submenu('Tools', Menu::new()
                ->link(url('/settings/backups'), 'Backup Now')
                ->link(url('/settings'), 'Settings')
                ->link(url('/users'), 'User Management')
            )
            ->submenu('Help', Menu::new()
                ->link(url('/about'), 'About')
                ->link('https://nativephp.com/docs/desktop/2/getting-started/introduction', 'Documentation')
            )
            ->register();

        MenuBar::create()
            ->onlyShowContextMenu()
            ->withContextMenu(
                MenuItem::make('Open POS')->link(url('/pos')),
                MenuItem::make('Open Reports')->link(url('/reports/sales/daily')),
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
}
