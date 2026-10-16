<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\View;

/**
 * A client build is the same codebase with `POS_CLIENT=<name>` (CLAUDE.md,
 * "Client builds"). Everything client-specific is data: values in
 * config/clients/<name>.php and templates in resources/clients/<name>/views.
 * With POS_CLIENT unset every method here is inert and the app is plain
 * LebaSouk — views and controllers ask this class, never compare strings.
 */
final class Client
{
    public static function name(): ?string
    {
        return config('pos.client') ?: null;
    }

    public static function is(string $client): bool
    {
        return self::name() === $client;
    }

    /** A value from the active profile's config file, or $default when there is none. */
    public static function config(string $key, mixed $default = null): mixed
    {
        $client = self::name();

        return $client ? config("clients.{$client}.{$key}", $default) : $default;
    }

    /**
     * The client's override of a core view if it ships one, else $fallback.
     * `Client::view('prints.delivery-note', 'prints.delivery-note')` looks for
     * resources/clients/{client}/views/prints/delivery-note.blade.php first.
     */
    public static function view(string $name, string $fallback): string
    {
        $client = self::name();

        if ($client) {
            View::replaceNamespace('client', resource_path("clients/{$client}/views"));

            if (View::exists("client::{$name}")) {
                return "client::{$name}";
            }
        }

        return $fallback;
    }

    /**
     * Write the profile's business defaults. Only SetupController calls this,
     * and setup only runs while no account exists — so there is no admin yet
     * whose edits this could overwrite. Keep it that way: don't call it from
     * anywhere an admin can reach.
     */
    public static function seedSettings(): void
    {
        foreach (self::config('settings', []) as $group => $values) {
            foreach ($values as $key => $value) {
                Setting::set($key, $value, $group);
            }
        }
    }
}
