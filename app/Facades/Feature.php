<?php

namespace App\Facades;

use App\Services\FeatureService;
use Illuminate\Support\Facades\Facade;

/**
 * @method static bool enabled(string $key)
 * @method static array all()
 * @method static string plan()
 * @method static array plans()
 * @method static array limits()
 * @method static array preset(?string $plan = null)
 * @method static array overrides()
 * @method static array preview(string $plan)
 * @method static array groups()
 * @method static array toggleable()
 * @method static string label(string $key)
 * @method static string groupLabel(string $group)
 * @method static void setPlan(string $plan, ?int $actorId = null)
 * @method static void override(string $key, ?bool $value, ?int $actorId = null)
 * @method static void sync(array $desired, ?int $actorId = null)
 * @method static void reset(?int $actorId = null)
 * @method static void flush()
 *
 * @see FeatureService
 */
class Feature extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return FeatureService::class;
    }
}
