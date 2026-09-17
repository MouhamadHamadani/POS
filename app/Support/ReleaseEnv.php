<?php

namespace App\Support;

use Dotenv\Dotenv;

/**
 * Is this machine's environment safe to package and hand to a client?
 *
 * This exists because it already nearly happened: 1.0.0 was about to be built
 * from an APP_ENV=local, APP_DEBUG=true environment, which puts a full PHP
 * stack trace on screen in front of whoever is standing at the till. A note in
 * INSTALL_NOTES.md did not stop it. A failing build does.
 *
 * Reads `.env` off disk rather than config(), for two reasons. A config cache
 * left behind by the previous build would otherwise answer for *that* build —
 * with config cached Laravel does not even load .env. And `.env` is the file
 * NativePHP actually bundles into the app, so it is the thing being judged.
 *
 * @see \App\Console\Commands\AssertReleaseEnv  the manual check
 * @see \App\Providers\AppServiceProvider        the hook that aborts native:build
 */
final class ReleaseEnv
{
    /**
     * Everything wrong with this machine's `.env`, as sentences. Empty is safe.
     *
     * @param  bool|null  $expectDemo  null skips the demo check — only the
     *                                 explicit command asserts intent that way.
     * @return array<int, string>
     */
    public static function problems(?bool $expectDemo = null): array
    {
        return self::problemsIn(self::read(), $expectDemo);
    }

    /**
     * The decision itself, over a parsed env. Separate from reading the file so
     * it can be tested without a `.env` on disk — and there isn't one in CI,
     * since `.env` is gitignored.
     *
     * @param  array<string, string|null>  $env
     * @return array<int, string>
     */
    public static function problemsIn(array $env, ?bool $expectDemo = null): array
    {
        $problems = [];

        $appEnv = $env['APP_ENV'] ?? '';
        if ($appEnv !== 'production') {
            $problems[] = 'APP_ENV is "'.$appEnv.'", expected "production".';
        }

        if (self::bool($env['APP_DEBUG'] ?? null)) {
            $problems[] = 'APP_DEBUG is true — a PHP error would print a stack trace at the till.';
        }

        if (($env['APP_KEY'] ?? '') === '') {
            $problems[] = 'APP_KEY is empty. Run `php artisan key:generate`.';
        }

        $isDemo = self::bool($env['POS_DEMO_MODE'] ?? null);

        if ($expectDemo !== null && $isDemo !== $expectDemo) {
            $problems[] = $isDemo
                ? 'POS_DEMO_MODE is on. A demo build ships known credentials — pass --demo if that is what you meant.'
                : 'POS_DEMO_MODE is off but --demo was passed.';
        }

        return $problems;
    }

    /** A one-line summary of what is about to be packaged. */
    public static function summary(): string
    {
        $env = self::read();

        return sprintf(
            '%s %s (%s%s)',
            $env['APP_NAME'] ?? config('app.name'),
            $env['NATIVEPHP_APP_VERSION'] ?? config('nativephp.version'),
            $env['APP_ENV'] ?? '?',
            self::bool($env['POS_DEMO_MODE'] ?? null) ? ', demo' : '',
        );
    }

    /** @return array<string, string|null> */
    private static function read(): array
    {
        $path = base_path('.env');

        return is_readable($path) ? Dotenv::parse(file_get_contents($path)) : [];
    }

    private static function bool(?string $value): bool
    {
        return filter_var($value ?? '', FILTER_VALIDATE_BOOLEAN);
    }
}
