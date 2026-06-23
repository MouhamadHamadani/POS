<?php

namespace App\Providers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        $path = DB::connection()->getDatabaseName();
        $isMemory = $path === ':memory:';

        if (! $isMemory && ! file_exists($path)) {
            @mkdir(dirname($path), 0755, true);
            touch($path);
        }

        // WAL is a file-mode journal; it's unavailable (and pointless) on :memory:.
        // Trying to enable it throws "database is locked" during PHPUnit setUp.
        if (! $isMemory) {
            DB::statement('PRAGMA journal_mode=WAL;');
            DB::statement('PRAGMA synchronous=NORMAL;');
        }

        DB::statement('PRAGMA foreign_keys=ON;');
        DB::statement('PRAGMA busy_timeout=5000;');
    }
}
