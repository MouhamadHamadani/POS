<?php

namespace App\Console\Commands;

use App\Support\FakeData;
use Database\Seeders\FakeDataSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fill a development database with a fake business, or take it back out again.
 *
 *   php artisan pos:fake-data            seed a database that has no business data yet
 *   php artisan pos:fake-data --fresh    wipe the fake data that is there and build it again
 *   php artisan pos:fake-data --purge    back to a clean, freshly installed database
 *   php artisan pos:fake-data --status   what is in this database, and what mode it is in
 *
 * Works whatever POS_FAKE_DATA says (that flag only decides whether an empty database is seeded automatically), but
 * never in production or a demo build, never into a database that already holds data it did not put there, and it
 * only ever purges a database that carries the seeder's marker. See App\Support\FakeData for the rules.
 */
class SeedFakeData extends Command
{
    protected $signature = 'pos:fake-data
                            {--fresh : Wipe the fake data already here and build it again}
                            {--purge : Remove the fake data and return to a clean, freshly installed database}
                            {--status : Say what this database holds and what mode it is in}
                            {--days= : Days of trading to generate (default: POS_FAKE_DATA_DAYS, 21)}
                            {--seed= : Random seed; the same seed gives the same shop}
                            {--force : Do not ask for confirmation}';

    protected $description = 'Seed a development database with a fake business, or reset it to a clean one';

    /** Tables worth counting in the status and purge summaries. */
    private const COUNTED = ['users', 'products', 'customers', 'suppliers', 'purchase_orders', 'shifts', 'sales', 'sale_payments', 'returns', 'expenses', 'held_sales'];

    public function handle(): int
    {
        if ($this->option('status')) {
            return $this->status();
        }

        if ($this->option('purge')) {
            return $this->purge();
        }

        return $this->seed();
    }

    private function status(): int
    {
        $this->line('Environment:   '.app()->environment().(app()->environment('production') ? '  (production: fake data is refused and removed)' : ''));
        $this->line('POS_FAKE_DATA:  '.(config('pos.fake_data') ? 'on' : 'off').'  (seeds an empty database on first launch when on)');
        $this->line('Fake data here: '.(FakeData::seeded() ? 'yes (marked)' : 'no'));
        $this->line('Business data:  '.(FakeData::hasBusinessData() ? 'yes' : 'none'));
        $this->newLine();

        foreach ($this->counts() as $table => $count) {
            $this->line(sprintf('  %-18s %d', $table, $count));
        }

        return self::SUCCESS;
    }

    private function purge(): int
    {
        if (! FakeData::seeded()) {
            $this->error('This database holds no fake data (it carries no fake-data marker), so it is left alone.');
            $this->line('To clear data that is not fake, on purpose, use: php artisan pos:reset-data');

            return self::FAILURE;
        }

        return $this->confirmThenReset('Remove all fake data and return this database to a clean, freshly installed state?')
            ? self::SUCCESS
            : self::FAILURE;
    }

    private function seed(): int
    {
        if ($this->option('fresh') && FakeData::seeded()) {
            if (! $this->confirmThenReset('Wipe the fake data already here and build it again?')) {
                return self::FAILURE;
            }
        }

        if ($problem = FakeData::problem()) {
            $this->error($problem);

            return self::FAILURE;
        }

        $seeder = app(FakeDataSeeder::class)->configure(
            days: (int) ($this->option('days') ?? config('pos.fake_data_days', 21)),
            seed: (int) ($this->option('seed') ?? FakeDataSeeder::DEFAULT_SEED),
        );

        $this->info('Seeding a fake business ('.$seeder->days.' days of trading, seed '.$seeder->seed.') ...');
        $started = microtime(true);
        $seeder->setContainer($this->laravel)->setCommand($this)->__invoke();

        $this->newLine();
        foreach ($seeder->summary as $what => $count) {
            $this->line(sprintf('  %-16s %d', $what, $count));
        }
        $this->newLine();
        $this->info(sprintf('Done in %.1fs. Sign in with fake_admin / fake_manager / fake_cashier / fake_cashier2 / fake_stock, password "%s".',
            microtime(true) - $started, config('pos.fake_password', 'test1234')));
        $this->line('fake_cashier has a shift open, so the till works straight away.');
        $this->line('Back to a clean database: php artisan pos:fake-data --purge  (or launch with APP_ENV=production)');

        return self::SUCCESS;
    }

    private function confirmThenReset(string $question): bool
    {
        $counts = array_filter($this->counts());

        $this->newLine();
        foreach ($counts as $table => $count) {
            $this->line(sprintf('  %-18s %d row%s', $table, $count, $count === 1 ? '' : 's'));
        }
        $this->newLine();

        if (! $this->option('force') && ! $this->confirm($question, false)) {
            $this->warn('Aborted. Nothing was deleted.');

            return false;
        }

        $rows = FakeData::reset();
        $this->info("Cleared {$rows} rows. The database is clean: config defaults and the built-in super admin only.");

        return true;
    }

    /** @return array<string,int> */
    private function counts(): array
    {
        $counts = [];
        foreach (self::COUNTED as $table) {
            if (Schema::hasTable($table)) {
                $counts[$table] = DB::table($table)->count();
            }
        }

        return $counts;
    }
}
