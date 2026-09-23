<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The fix for the `spawn UNKNOWN` crash lives in vendor/, which no commit can
 * hold (see App\Console\Commands\PatchNativePhpScheduler). `native:build`
 * refuses to package if the patch stops applying — this is what says so first,
 * on the `composer update` that moved the code it anchors on rather than on the
 * release build three weeks later.
 */
class NativePhpSchedulerTest extends TestCase
{
    public function test_the_vendor_patch_is_applied(): void
    {
        $this->artisan('nativephp:patch-scheduler', ['--check' => true])
            ->assertExitCode(0);
    }

    public function test_the_config_key_the_patch_reads_still_exists(): void
    {
        // The patched Electron code gates on `config.scheduler.enabled === true`
        // after the array crosses over as JSON from `artisan native:config`.
        // A non-boolean here (a bare env() returning the string "1") is never
        // true on the other side, so the scheduler would silently never run.
        $this->assertIsBool(config('nativephp.scheduler.enabled'));
    }
}
