<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fake data mode: a fake business to develop and test against, that can never end up in front of a client.
 *
 * Three rules keep it that way, and every other piece of fake data code goes through them:
 *
 *  1. It never seeds in a production environment, in a demo build (which has its own seeded template), or into a
 *     database that already holds business data it did not put there.
 *  2. Everything it writes is marked: the seeder's first write is the `fake_data_seeded` setting, so even a seed
 *     that died halfway is recognisable, and the purge works from that marker alone.
 *  3. Launching in a production environment resets a marked database to a clean one, and a database WITHOUT the
 *     marker is never wiped by any of this. (`pos:reset-data` is the deliberate way to clear real data.)
 *
 * "Clean" is what a freshly installed machine looks like: every table emptied, then the config defaults and the
 * vendor's built-in super_admin laid down again, exactly as NativeAppServiceProvider does for an empty database.
 */
final class FakeData
{
    public const MARKER = 'fake_data_seeded';

    /** Set while a purge lays the defaults back down, so DatabaseSeeder does not fake it all over again. */
    private static bool $suspended = false;

    /** Seed automatically (DatabaseSeeder): the flag is on, and this is not production, a demo or a purge in progress. */
    public static function enabled(): bool
    {
        return (bool) config('pos.fake_data', false)
            && ! self::$suspended
            && ! app()->environment('production')
            && ! Demo::enabled();
    }

    /** Does this database carry the seeder's marker? Read straight from the table: nothing here may trust a cache. */
    public static function seeded(): bool
    {
        return Schema::hasTable('settings')
            && Setting::query()->where('key', self::MARKER)->exists();
    }

    /**
     * Why fake data cannot be seeded here and now, or null when it can.
     *
     * @param  bool  $rebuild  the caller is about to purge a marked database first, so "already seeded" is fine
     */
    public static function problem(bool $rebuild = false): ?string
    {
        if (app()->environment('production')) {
            return 'This is a production environment. Fake data is for development and testing only.';
        }

        if (Demo::enabled()) {
            return 'This is a demo build: it restores its own seeded template on every launch (php artisan demo:build-template).';
        }

        $marked = self::seeded();

        if ($marked && ! $rebuild) {
            return 'This database already holds fake data. Rebuild it with --fresh, or return it to a clean state with --purge.';
        }

        if (! $marked && self::hasBusinessData()) {
            return 'This database already holds business data that did not come from the fake-data seeder, so it is left alone. '
                .'Point the app at an empty database, or clear this one deliberately with php artisan pos:reset-data.';
        }

        return null;
    }

    /** Catalogue, customers, suppliers or any sale: what a real business has and a freshly installed machine does not. */
    public static function hasBusinessData(): bool
    {
        foreach (['products', 'categories', 'customers', 'suppliers', 'sales', 'shifts'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                return true;
            }
        }

        return false;
    }

    /** A marked database on a production launch: the one situation that resets itself. */
    public static function switchedToProduction(): bool
    {
        return app()->environment('production') && ! Demo::enabled() && self::seeded();
    }

    /**
     * Fake data in a production launch is wiped; anything else is left exactly as it is.
     *
     * @return bool whether a reset happened
     */
    public static function resetIfSwitchedToProduction(): bool
    {
        if (! self::switchedToProduction()) {
            return false;
        }

        self::reset();
        logger()->warning('Fake data found on a production launch: the database was reset to a clean install.');

        return true;
    }

    /**
     * Back to a freshly installed database: every table emptied (migrations excepted), then the defaults and the
     * built-in super_admin seeded again. Only ever for a database that carries the marker; callers check.
     *
     * @return int rows removed
     */
    public static function reset(): int
    {
        $rows = 0;

        Schema::disableForeignKeyConstraints();
        try {
            DB::transaction(function () use (&$rows) {
                foreach (self::tables() as $table) {
                    $rows += DB::table($table)->count();
                    DB::table($table)->delete();
                }

                // AUTOINCREMENT back to the start, so the clean database numbers its first sale 1.
                if (Schema::hasTable('sqlite_sequence')) {
                    DB::table('sqlite_sequence')->delete();
                }
            });
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        // Settings are cached forever and the feature map is memoised: neither may outlive the rows behind them.
        Cache::flush();
        app(\App\Services\FeatureService::class)->flush();

        self::$suspended = true;
        try {
            Artisan::call('db:seed', ['--force' => true]);
        } finally {
            self::$suspended = false;
        }

        Cache::flush();

        return $rows;
    }

    /**
     * Every table that holds data (all but the migration ledger), children before the tables they point at, so
     * the delete is valid even where foreign keys cannot be switched off (inside a transaction, SQLite ignores
     * that pragma). Worked out from the schema itself, so it cannot drift when a migration adds a table.
     *
     * @return list<string>
     */
    private static function tables(): array
    {
        $names = collect(Schema::getTables())
            ->pluck('name')
            // expense_categories is reference data its own migration inserts (no seeder does), and the fake data
            // never adds to it: emptying it would leave the clean database with no categories to book expenses to.
            ->reject(fn (string $name) => in_array($name, ['migrations', 'expense_categories'], true) || str_starts_with($name, 'sqlite_'))
            ->values()
            ->all();

        $children = [];
        foreach ($names as $table) {
            foreach (Schema::getForeignKeys($table) as $fk) {
                if ($fk['foreign_table'] !== $table) {
                    $children[$fk['foreign_table']][] = $table;
                }
            }
        }

        $order = [];
        $seen = [];
        $visit = function (string $table) use (&$visit, &$order, &$seen, $children): void {
            if (isset($seen[$table])) {
                return;
            }
            $seen[$table] = true;
            foreach ($children[$table] ?? [] as $child) {
                $visit($child);
            }
            $order[] = $table;
        };
        foreach ($names as $table) {
            $visit($table);
        }

        return $order;
    }
}
