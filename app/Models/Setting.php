<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    use HasFactory;

    protected $fillable = [
        'key', 'value', 'group', 'type', 'description', 'is_public',
    ];

    protected $casts = [
        'is_public' => 'boolean',
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        $raw = Cache::rememberForever("setting:{$key}", function () use ($key) {
            return static::query()->where('key', $key)->first(['value', 'type']);
        });

        if (!$raw) {
            return $default;
        }

        return match ($raw->type) {
            'int' => (int) $raw->value,
            'float' => (float) $raw->value,
            'bool' => filter_var($raw->value, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode($raw->value, true),
            default => $raw->value,
        };
    }

    public static function set(string $key, mixed $value, string $group = 'general', string $type = 'string'): void
    {
        $stored = $type === 'json' ? json_encode($value) : (string) $value;
        static::updateOrCreate(
            ['key' => $key],
            ['value' => $stored, 'group' => $group, 'type' => $type]
        );
        Cache::forget("setting:{$key}");
    }
}
