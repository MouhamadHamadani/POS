<?php

return [
    /**
     * The version of your app.
     * It is used to determine if the app needs to be updated.
     * Increment this value every time you release a new version of your app.
     */
    'version' => env('NATIVEPHP_APP_VERSION', '1.0.0'),

    /**
     * The ID of your application. This should be a unique identifier
     * usually in the form of a reverse domain name.
     * For example: com.nativephp.app
     */
    'app_id' => env('NATIVEPHP_APP_ID', 'com.buildsyntax.lebasouk'),

    /**
     * If your application allows deep linking, you can specify the scheme
     * to use here. This is the scheme that will be used to open your
     * application from within other applications.
     * For example: "nativephp"
     *
     * This would allow you to open your application using a URL like:
     * nativephp://some/path
     */
    'deeplink_scheme' => env('NATIVEPHP_DEEPLINK_SCHEME'),

    /**
     * The author of your application.
     */
    'author' => env('NATIVEPHP_APP_AUTHOR', 'Build Syntax'),

    /**
     * The copyright notice for your application.
     */
    'copyright' => env('NATIVEPHP_APP_COPYRIGHT'),

    /**
     * The description of your application.
     */
    'description' => env('NATIVEPHP_APP_DESCRIPTION', 'LebaSouk — point of sale for Lebanese retail'),

    /**
     * The Website of your application.
     */
    'website' => env('NATIVEPHP_APP_WEBSITE'),

    /**
     * The default service provider for your application. This provider
     * takes care of bootstrapping your application and configuring
     * any global hotkeys, menus, windows, etc.
     */
    'provider' => \App\Providers\NativeAppServiceProvider::class,

    /**
     * A list of environment keys that should be removed from the
     * .env file when the application is bundled for production.
     * You may use wildcards to match multiple keys.
     */
    'cleanup_env_keys' => [
        'AWS_*',
        'AZURE_*',
        'GITHUB_*',
        'DO_SPACES_*',
        '*_SECRET',
        'ZEPHPYR_*',
        'NATIVEPHP_UPDATER_PATH',
        'NATIVEPHP_APPLE_ID',
        'NATIVEPHP_APPLE_ID_PASS',
        'NATIVEPHP_APPLE_TEAM_ID',
        'NATIVEPHP_AZURE_PUBLISHER_NAME',
        'NATIVEPHP_AZURE_ENDPOINT',
        'NATIVEPHP_AZURE_CERTIFICATE_PROFILE_NAME',
        'NATIVEPHP_AZURE_CODE_SIGNING_ACCOUNT_NAME',
    ],

    /**
     * A list of files and folders that should be removed from the
     * final app before it is bundled for production.
     * You may use glob / wildcard patterns here.
     */
    'cleanup_exclude_files' => [
        'build',
        'temp',
        'content',
        'node_modules',
        '*/tests',
    ],

    /**
     * The NativePHP updater configuration.
     */
    'updater' => [
        /**
         * Whether or not the updater is enabled. Please note that the
         * updater will only work when your application is bundled
         * for production.
         *
         * Defaults to FALSE on purpose: none of the three providers below has
         * real credentials, and nothing is published on build. An updater that
         * is on but pointed at nothing fails on every launch of a till that has
         * no internet anyway. Turn it on only together with a wired provider
         * and `native:build win x64 --publish` (INSTALL_NOTES.md section 6).
         */
        'enabled' => env('NATIVEPHP_UPDATER_ENABLED', false),

        /**
         * The updater provider to use.
         * Supported: "github", "s3", "spaces"
         */
        'default' => env('NATIVEPHP_UPDATER_PROVIDER', 'spaces'),

        'providers' => [
            'github' => [
                'driver' => 'github',
                'repo' => env('GITHUB_REPO'),
                'owner' => env('GITHUB_OWNER'),
                'token' => env('GITHUB_TOKEN'),
                'vPrefixedTagName' => env('GITHUB_V_PREFIXED_TAG_NAME', true),
                'private' => env('GITHUB_PRIVATE', false),
                'channel' => env('GITHUB_CHANNEL', 'latest'),
                'releaseType' => env('GITHUB_RELEASE_TYPE', 'draft'),
            ],

            's3' => [
                'driver' => 's3',
                'key' => env('AWS_ACCESS_KEY_ID'),
                'secret' => env('AWS_SECRET_ACCESS_KEY'),
                'region' => env('AWS_DEFAULT_REGION'),
                'bucket' => env('AWS_BUCKET'),
                'endpoint' => env('AWS_ENDPOINT'),
                'path' => env('NATIVEPHP_UPDATER_PATH', null),
            ],

            'spaces' => [
                'driver' => 'spaces',
                'key' => env('DO_SPACES_KEY_ID'),
                'secret' => env('DO_SPACES_SECRET_ACCESS_KEY'),
                'name' => env('DO_SPACES_NAME'),
                'region' => env('DO_SPACES_REGION'),
                'path' => env('NATIVEPHP_UPDATER_PATH', null),
            ],
        ],
    ],

    /**
     * The queue workers that get auto-started on your application start.
     */
    'queue_workers' => [
        'default' => [
            'queues' => ['default'],
            'memory_limit' => 128,
            'timeout' => 60,
            'sleep' => 3,
        ],
    ],

    /**
     * The Electron main process' scheduler loop.
     *
     * NativePHP runs `artisan schedule:run` on a 60-second timer, spawning the
     * bundled php.exe every tick. This app schedules nothing (routes/console.php
     * only has the stock `inspire` example), so all of that was work for no
     * work — and on one client machine an antivirus that had quarantined
     * php.exe turned a tick into `spawn UNKNOWN`, uncaught in Electron's main
     * process, which killed the till mid-shift.
     *
     * Defaults to FALSE on purpose, same reasoning as `updater` above: don't run
     * a background loop that has nothing to do. Turn it on in the same change
     * that adds the first real scheduled task (the auto-backup behind
     * Settings -> Backup is the obvious candidate).
     *
     * Upstream ships no toggle of its own — https://github.com/NativePHP/desktop/issues/147
     * — so this key is read by a patch applied to the vendored Electron plugin;
     * see app/Console/Commands/PatchNativePhpScheduler.php. filter_var, not a
     * bare env(), because the value crosses into JavaScript as JSON and a
     * literal "1" there is not `true`.
     */
    'scheduler' => [
        'enabled' => filter_var(env('NATIVEPHP_SCHEDULER_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    ],

    /**
     * Define your own scripts to run before and after the build process.
     */
    'prebuild' => array_values(array_filter([
        // Nothing that has to *stop* the build belongs in this list: NativePHP
        // prints when a prebuild command fails and carries on regardless
        // (HasPreAndPostProcessing::runProcess). The release check is hooked
        // onto the command itself in App\Providers\AppServiceProvider.
        'php artisan optimize:clear', // drop the previous build's caches
        'npm run build', // Run a command before the build
        'php artisan optimize', // Run another command before the build

        // Demo builds ship a freshly seeded template to reset themselves from.
        // It is not committed (resources/demo/.gitignore excludes it), so it is
        // built here rather than being something a release can forget. env() and
        // not config(): config files are loaded before this one can read another.
        filter_var(env('POS_DEMO_MODE', false), FILTER_VALIDATE_BOOLEAN)
            ? 'php artisan demo:build-template'
            : null,
    ])),
    'postbuild' => [
        // 'npm run release', // Disabled — no publish target configured
    ],

    /**
     * Custom PHP binary path.
     */
    'binary_path' => env('NATIVEPHP_PHP_BINARY_PATH', null),
];
