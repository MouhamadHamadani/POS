<?php

namespace App\Console\Commands;

use App\Support\ReleaseEnv;
use Illuminate\Console\Command;

/**
 * The manual form of the release check. `native:build` is gated automatically
 * (see App\Providers\AppServiceProvider); this is for checking a machine before
 * you kick a build off, and for CI.
 */
class AssertReleaseEnv extends Command
{
    protected $signature = 'pos:assert-release-env {--demo : This is a demo build, so POS_DEMO_MODE is expected to be on}';

    protected $description = 'Fail unless .env is safe to package and hand to a client';

    public function handle(): int
    {
        $problems = ReleaseEnv::problems($this->option('demo'));

        if ($problems !== []) {
            $this->error('This environment is not safe to ship:');
            foreach ($problems as $problem) {
                $this->line('  - '.$problem);
            }

            return self::FAILURE;
        }

        $this->info('Release environment OK — '.ReleaseEnv::summary().'.');

        return self::SUCCESS;
    }
}
