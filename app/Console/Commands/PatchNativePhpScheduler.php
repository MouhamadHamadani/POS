<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Makes NativePHP's Electron-side scheduler loop opt-in and crash-proof.
 *
 * Upstream starts `artisan schedule:run` on a 60-second timer the moment the
 * app boots, with no config key, no env var and no hook to turn it off
 * (https://github.com/NativePHP/desktop/issues/147 — still open against the v2
 * line; this app is on nativephp/electron 1.3, which carries the same code).
 * This app schedules nothing at all (routes/console.php), so every tick spawned
 * the bundled php.exe to do no work — and on a client machine an antivirus that
 * had quarantined php.exe turned one of those ticks into `spawn UNKNOWN`,
 * thrown uncaught in Electron's main process, which killed the till mid-shift.
 *
 * Two changes, both inside vendor/nativephp/electron/resources/js/electron-plugin:
 *
 *  1. `startScheduler()` in index.ts takes the config the main process already
 *     fetched from `artisan native:config` (which is just `config('nativephp')`)
 *     and returns early unless `nativephp.scheduler.enabled` is true. No patch
 *     is needed to *read* the flag — the whole config array already crosses
 *     over — so the switch itself lives in our own config/nativephp.php and
 *     survives a vendor wipe on its own.
 *  2. `startScheduler()` in server/php.ts wraps the spawn. Node throws
 *     synchronously for UNKNOWN and emits 'error' for ENOENT/EACCES, so both
 *     paths have to be covered; a tick that cannot start PHP is logged and
 *     skipped, and the next tick tries again. This is the half that also
 *     protects whatever real scheduled job turns the flag back on.
 *
 * Why anchored string replacement rather than cweagans/composer-patches: what
 * actually runs is the *compiled* electron-plugin/dist/*.js, a context diff
 * against compiled output is no less brittle than this and fails hard once
 * already applied, and composer-patches needs a `patch` or `git apply` binary on
 * PATH — not something to lean on for a Windows build machine. This is plain
 * PHP, idempotent, and adds no dependency.
 *
 * Applied automatically after `composer install` / `composer update`
 * (composer.json scripts) and re-asserted before every `native:build`
 * (App\Providers\AppServiceProvider), so a dependency install cannot silently
 * put the crash back.
 */
class PatchNativePhpScheduler extends Command
{
    protected $signature = 'nativephp:patch-scheduler
        {--check : Report whether the patch is applied; change nothing}';

    protected $description = 'Make the NativePHP Electron scheduler opt-in and non-fatal (vendor patch)';

    /** Presence of this string in a file means that file is already patched. */
    private const MARKER = 'NATIVEPHP_SCHEDULER_PATCH';

    public function handle(): int
    {
        $root = base_path('vendor/nativephp/electron/resources/js/electron-plugin');

        if (! is_dir($root)) {
            $this->line('nativephp/electron is not installed — nothing to patch.');

            return self::SUCCESS;
        }

        $problems = [];
        $patched = [];

        foreach ($this->replacements() as $relative => $rules) {
            $path = $root.'/'.$relative;

            if (! is_file($path)) {
                $problems[] = "{$relative}: file not found";

                continue;
            }

            $contents = str_replace("\r\n", "\n", file_get_contents($path));

            if (str_contains($contents, self::MARKER)) {
                continue;
            }

            if ($this->option('check')) {
                $problems[] = "{$relative}: not patched";

                continue;
            }

            foreach ($rules as [$search, $replace]) {
                if (! str_contains($contents, $search)) {
                    $problems[] = "{$relative}: expected code not found — NativePHP has changed, review this patch";

                    continue 2;
                }

                $contents = str_replace($search, $replace, $contents);
            }

            file_put_contents($path, $contents);
            $patched[] = $relative;
        }

        if ($problems !== []) {
            $this->error('NativePHP scheduler patch could not be applied:');
            foreach ($problems as $problem) {
                $this->line('  - '.$problem);
            }

            return self::FAILURE;
        }

        $this->info($patched === []
            ? 'NativePHP scheduler patch already applied.'
            : 'NativePHP scheduler patched: '.implode(', ', $patched).'.');

        return self::SUCCESS;
    }

    /**
     * Anchors and their replacements, per file, relative to electron-plugin/.
     *
     * dist/ is what actually ships — `npm run build` is `electron-vite build`,
     * which bundles electron-plugin/dist/index.js through the `#plugin` import
     * and never runs `plugin:build`. src/ is patched alongside it purely so the
     * two don't diverge if anyone ever does recompile.
     *
     * @return array<string, array<int, array{0: string, 1: string}>>
     */
    private function replacements(): array
    {
        $gateJs = <<<'JS'
        // --- NATIVEPHP_SCHEDULER_PATCH -----------------------------------
        // Opt-in. See nativephp.scheduler.enabled in config/nativephp.php, and
        // app/Console/Commands/PatchNativePhpScheduler.php for the why.
        if (!config || !config.scheduler || config.scheduler.enabled !== true) {
            console.log("Scheduler disabled (nativephp.scheduler.enabled is off).");
            return;
        }
JS;

        $gateTs = <<<'TS'
    // --- NATIVEPHP_SCHEDULER_PATCH -----------------------------------
    // Opt-in. See nativephp.scheduler.enabled in config/nativephp.php, and
    // app/Console/Commands/PatchNativePhpScheduler.php for the why.
    if (!config || !config.scheduler || config.scheduler.enabled !== true) {
      console.log("Scheduler disabled (nativephp.scheduler.enabled is off).");
      return;
    }
TS;

        $guardJs = <<<'JS'
    // --- NATIVEPHP_SCHEDULER_PATCH -----------------------------------
    // A spawn failure here reached Electron's main process uncaught and took
    // the whole till down. Node throws synchronously for UNKNOWN (an antivirus
    // that ate the bundled php.exe is the case actually seen in the field) and
    // emits 'error' for ENOENT/EACCES, so both paths need covering. A tick that
    // can't start PHP is logged and skipped; the next one tries again.
    try {
        const child = callPhp(['artisan', 'schedule:run'], phpOptions, phpIniSettings);
        child.on('error', logSchedulerSpawnFailure);
        return child;
    }
    catch (error) {
        logSchedulerSpawnFailure(error);
        return null;
    }
}
function logSchedulerSpawnFailure(error) {
    const message = `scheduler could not start PHP (${state.php}): ${(error && error.message) || error}`;
    console.error('[nativephp]', message);
    try {
        appendFileSync(join(storagePath, 'logs', 'nativephp-main.log'), `[${new Date().toISOString()}] ${message}\n`);
    }
    catch (e) {
        // Logging a failure must never become a second failure.
    }
}
JS;

        $guardTs = <<<'TS'
    // --- NATIVEPHP_SCHEDULER_PATCH -----------------------------------
    // A spawn failure here reached Electron's main process uncaught and took
    // the whole till down. Node throws synchronously for UNKNOWN (an antivirus
    // that ate the bundled php.exe is the case actually seen in the field) and
    // emits 'error' for ENOENT/EACCES, so both paths need covering. A tick that
    // can't start PHP is logged and skipped; the next one tries again.
    try {
        const child = callPhp(['artisan', 'schedule:run'], phpOptions, phpIniSettings);
        child.on('error', logSchedulerSpawnFailure);
        return child;
    } catch (error) {
        logSchedulerSpawnFailure(error);
        return null;
    }
}

function logSchedulerSpawnFailure(error) {
    const message = `scheduler could not start PHP (${state.php}): ${(error && error.message) || error}`;
    console.error('[nativephp]', message);

    try {
        appendFileSync(join(storagePath, 'logs', 'nativephp-main.log'), `[${new Date().toISOString()}] ${message}\n`);
    } catch (e) {
        // Logging a failure must never become a second failure.
    }
}
TS;

        $spawnAnchor = "    return callPhp(['artisan', 'schedule:run'], phpOptions, phpIniSettings);\n}";

        return [
            // The gate. Both call sites sit inside bootstrapApp(), where the
            // config fetched from `artisan native:config` is already in scope.
            'dist/index.js' => [
                ['this.startScheduler();', 'this.startScheduler(config);'],
                [
                    "    startScheduler() {\n        const now = new Date();",
                    "    startScheduler(config) {\n".$gateJs."\n        const now = new Date();",
                ],
            ],

            'src/index.ts' => [
                ['this.startScheduler();', 'this.startScheduler(config);'],
                [
                    "  private startScheduler() {\n    const now = new Date();",
                    "  private startScheduler(config?: any) {\n".$gateTs."\n    const now = new Date();",
                ],
            ],

            // The crash. `storagePath`, `join` and `state` are already in scope
            // in this module; only appendFileSync has to be imported.
            'dist/server/php.js' => [
                [
                    "import { mkdirSync, statSync, writeFileSync, existsSync } from 'fs';",
                    "import { mkdirSync, statSync, writeFileSync, existsSync, appendFileSync } from 'fs';",
                ],
                [$spawnAnchor, $guardJs],
            ],

            'src/server/php.ts' => [
                [
                    "import {mkdirSync, statSync, writeFileSync, existsSync} from 'fs'",
                    "import {mkdirSync, statSync, writeFileSync, existsSync, appendFileSync} from 'fs'",
                ],
                [$spawnAnchor, $guardTs],
            ],
        ];
    }
}
