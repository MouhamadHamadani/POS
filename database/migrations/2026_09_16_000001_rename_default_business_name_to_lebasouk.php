<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

/**
 * The product was renamed from "POS Pro" to "LebaSouk".
 *
 * DefaultSettingsSeeder carries the new name, but it only runs on a machine's
 * very first launch (NativeAppServiceProvider seeds only when the users table
 * is empty), so an existing install would keep serving the old name on the POS
 * header and on every printed receipt. Migrations do run on every launch, so
 * the rename belongs here.
 *
 * Only values still equal to the old seeded default are touched — a client who
 * has set their own business name in Settings keeps it.
 */
return new class extends Migration
{
    /** @var array<string, array{0: string, 1: string, 2: string}> key => [old, new, group] */
    private const RENAMES = [
        'business_name' => ['POS Pro', 'LebaSouk', 'general'],
        'business_name_ar' => ['بوس برو', 'ليبا سوق', 'general'],
        'receipt_header' => [
            "POS Pro\nThank you for your business",
            "LebaSouk\nThank you for your business",
            'receipt',
        ],
    ];

    public function up(): void
    {
        $this->rename(fn (array $r) => [$r[0], $r[1], $r[2]]);
    }

    public function down(): void
    {
        $this->rename(fn (array $r) => [$r[1], $r[0], $r[2]]);
    }

    /**
     * @param  callable(array{0: string, 1: string, 2: string}): array{0: string, 1: string, 2: string}  $direction
     */
    private function rename(callable $direction): void
    {
        foreach (self::RENAMES as $key => $row) {
            [$from, $to, $group] = $direction($row);

            if (Setting::get($key) === $from) {
                Setting::set($key, $to, $group);
            }
        }
    }
};
