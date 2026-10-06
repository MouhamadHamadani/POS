<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Display label for a stored enum-ish value (status, role, reason, ...).
 * Reads lang enums.{group}.{value}; a value with no translation falls back to
 * a readable form of itself, so a newly added value never renders blank.
 */
final class EnumLabel
{
    public static function get(string $group, ?string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $key = "enums.{$group}.{$value}";
        $label = __($key);

        return $label === $key ? Str::headline($value) : $label;
    }
}
