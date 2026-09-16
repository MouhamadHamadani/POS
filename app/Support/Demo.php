<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Everything the demo build needs to know about itself.
 *
 * A demo build is the same codebase as production with `POS_DEMO_MODE=true`.
 * The difference it makes is deliberately narrow: a throwaway database restored
 * from a seeded template on every launch, no action that reaches the outside
 * world, and a banner saying so. Pricing, VAT, currency, roles and printing all
 * behave exactly as they do for a paying client — demonstrating that is the
 * whole point of the build.
 */
final class Demo
{
    /**
     * A demo build may only ever open a database whose path contains this.
     *
     * Packaged, the runtime database lives under %APPDATA%/<APP_NAME>/database,
     * so APP_NAME="LebaSouk Demo" puts the marker in the path; locally,
     * DB_DATABASE=database/demo.sqlite does. A production install resolves to
     * %APPDATA%/LebaSouk/database — no marker — and guardDatabasePath() refuses
     * to boot rather than reset somebody's real till to the demo catalogue.
     */
    private const PATH_MARKER = 'demo';

    public static function enabled(): bool
    {
        return (bool) config('pos.demo_mode', false);
    }

    /** Shared password for the four seeded demo accounts. */
    public static function password(): string
    {
        return (string) config('pos.demo_password', 'demo1234');
    }

    /** Appended to window and page titles so a demo is obvious in the taskbar. */
    public static function titleSuffix(): string
    {
        return self::enabled() ? ' (Demo)' : '';
    }

    /**
     * The seeded baseline, built by `php artisan demo:build-template`.
     * Not committed — database/.gitignore excludes *.sqlite* — so the demo
     * build regenerates it at build time (see docs/demo-build.md).
     */
    public static function templatePath(): string
    {
        return database_path('demo-template.sqlite');
    }

    /** The database this process is actually connected to, after NativePHP's rewrite. */
    public static function databasePath(): string
    {
        $connection = config('database.default');

        return (string) config("database.connections.{$connection}.database");
    }

    /**
     * Refuse to run a demo build against anything that isn't a demo database.
     * Called on every request from AppServiceProvider — env vars get
     * fat-fingered, and the failure mode we are buying out of is a demo build
     * quietly overwriting a client's live data with the sample catalogue.
     */
    public static function guardDatabasePath(): void
    {
        $path = self::databasePath();

        // Tests run on :memory:, which is nobody's production database.
        if ($path === '' || $path === ':memory:') {
            return;
        }

        // Separator-agnostic: the marker is a plain word, not a path fragment.
        if (! str_contains(strtolower($path), self::PATH_MARKER)) {
            throw new RuntimeException(
                "LebaSouk refused to start in demo mode: the database path does not look like a demo data directory.\n"
                ."  Resolved: {$path}\n"
                ."  A demo build must never open a production database. Either set APP_NAME=\"LebaSouk Demo\" "
                ."(packaged build) / DB_DATABASE=database/demo.sqlite (local), or unset POS_DEMO_MODE."
            );
        }
    }

    /**
     * Restore the live database from the seeded template — the whole of "reset
     * the demo". Runs once per launch before the window opens, and again behind
     * the sidebar button when a pitch needs a clean slate mid-meeting.
     */
    public static function resetFromTemplate(): void
    {
        $template = self::templatePath();
        $live = self::databasePath();

        if (! is_file($template)) {
            throw new RuntimeException(
                "Demo template missing: {$template}. Run `php artisan demo:build-template` before building the demo."
            );
        }

        self::guardDatabasePath();

        if ($live === '' || $live === ':memory:') {
            throw new RuntimeException('Demo reset needs a file-backed database; got "'.$live.'".');
        }

        // Let go of this request's handle before the file moves underneath it.
        DB::disconnect();

        if (! is_dir(dirname($live))) {
            @mkdir(dirname($live), 0755, true);
        }

        // ponytail: copy with a short retry rather than a lock protocol. The app
        // runs WAL, and NativePHP keeps queue workers polling the same file, so a
        // copy can lose a race for a few hundred ms on Windows. If a demo ever
        // needs a guaranteed-quiet reset, stop the workers first.
        $error = 'unknown error';

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            self::clearSidecars($live);

            if (@copy($template, $live)) {
                // A -wal written between the unlink above and the copy would be
                // replayed over the fresh file; drop it once more to be sure.
                self::clearSidecars($live);
                Cache::flush();

                return;
            }

            $error = error_get_last()['message'] ?? $error;
            usleep(200_000);
        }

        throw new RuntimeException("Could not reset the demo database ({$live}): {$error}");
    }

    /** WAL/shared-memory sidecars belong to the file we just replaced. */
    private static function clearSidecars(string $database): void
    {
        foreach (['-wal', '-shm'] as $suffix) {
            @unlink($database.$suffix);
        }
    }
}
