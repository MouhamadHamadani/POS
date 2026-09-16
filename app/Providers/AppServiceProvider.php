<?php

namespace App\Providers;

use App\Support\Demo;
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
        // Runs after NativePHP has rewritten the connection to the packaged
        // app's data directory (package providers boot before app providers),
        // so this sees the path the app will actually open. A demo build that
        // resolves to anything but a demo database refuses to serve at all —
        // better a hard stop than a demo reset landing on a client's till.
        //
        // Requests only. A build machine runs `key:generate`, `optimize` and
        // `demo:build-template` with POS_DEMO_MODE already set but the project's
        // own sqlite path still configured, and failing those would stop the
        // demo being built at all. Nothing destructive rides on this branch:
        // Demo::resetFromTemplate() runs the same guard itself, console or not.
        if (Demo::enabled() && ! $this->app->runningInConsole()) {
            Demo::guardDatabasePath();
        }

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
