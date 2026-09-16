<?php

namespace App\Console\Commands;

use App\Support\Demo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Builds resources/demo/demo-template.sqlite — the baseline every demo launch
 * is restored from.
 *
 * Runs against a scratch file, never the connection this process started on, so
 * it can't touch a working database by accident. It lives under resources/ and
 * not database/, because NativePHP strips database/*.sqlite from the packaged
 * app. The template is not committed; config/nativephp.php runs this as a
 * prebuild step when POS_DEMO_MODE is set, so a demo build always carries a
 * freshly seeded one.
 */
class DemoBuildTemplate extends Command
{
    protected $signature = 'demo:build-template {--path= : Write the template somewhere other than the default}';

    protected $description = 'Generate the seeded SQLite template a demo build resets itself from';

    public function handle(): int
    {
        $target = $this->option('path') ?: Demo::templatePath();
        $scratch = $target.'.building';

        foreach ([$scratch, $scratch.'-wal', $scratch.'-shm'] as $stale) {
            @unlink($stale);
        }

        if (! is_dir(dirname($target))) {
            @mkdir(dirname($target), 0755, true);
        }

        touch($scratch);

        // Point the default connection at the scratch file for the rest of this
        // command. migrate:fresh is destructive by definition — it must never
        // find a real database on the other end of it.
        config(['database.connections.demo_template' => [
            'driver' => 'sqlite',
            'database' => $scratch,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        config(['database.default' => 'demo_template']);
        DB::purge();

        $this->line("Building demo template at {$target}");

        Artisan::call('migrate:fresh', ['--force' => true], $this->getOutput());
        Artisan::call('db:seed', ['--force' => true], $this->getOutput());
        Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\DemoSeeder', '--force' => true], $this->getOutput());

        // Fold the WAL back in so the copied file is complete on its own.
        DB::statement('PRAGMA wal_checkpoint(TRUNCATE);');
        DB::disconnect();

        foreach ([$target, $target.'-wal', $target.'-shm'] as $stale) {
            @unlink($stale);
        }

        if (! @rename($scratch, $target)) {
            $this->error("Could not move {$scratch} to {$target}.");

            return self::FAILURE;
        }

        foreach ([$scratch.'-wal', $scratch.'-shm'] as $sidecar) {
            @unlink($sidecar);
        }

        $this->newLine();
        $this->info(sprintf('Demo template written: %s (%s KB)', $target, number_format(filesize($target) / 1024, 1)));

        return self::SUCCESS;
    }
}
