<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Demo;
use App\Support\ReleaseEnv;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Single-purpose gate, not a permissions system: `admin` and `stock` are
        // the only eligible roles and a super-admin switches each one on or off
        // (Settings -> Permissions). Defined here so routes can use the built-in
        // `can:` middleware instead of a bespoke one.
        Gate::define('bulk-upload-products', fn (User $user) => $user->canBulkUploadProducts());

        $this->guardReleaseBuilds();

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

    /**
     * Refuse to package a build that is not safe to hand to a client.
     *
     * This has to hook the command itself, not `prebuild`. NativePHP runs the
     * prebuild list and merely *prints* when one fails — HasPreAndPostProcessing
     * returns from the closure and carries on to the next command — so a guard
     * wired there would stand and watch the bad build get made. CommandStarting
     * runs inside `native:build`'s own process, where throwing actually stops it.
     */
    private function guardReleaseBuilds(): void
    {
        Event::listen(function (CommandStarting $event) {
            if ($event->command !== 'native:build') {
                return;
            }

            // Intent is not asserted here: a demo build is a legitimate use of
            // native:build, and POS_DEMO_MODE is the only thing that says which
            // kind this is. `pos:assert-release-env --demo` is where you say it
            // out loud.
            $problems = ReleaseEnv::problems();

            if ($problems !== []) {
                throw new \RuntimeException(
                    'Refusing to build '.ReleaseEnv::summary().". Fix .env first:\n  - "
                    .implode("\n  - ", $problems)
                );
            }
        });
    }
}
