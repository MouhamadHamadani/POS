<?php

namespace App\Services;

use App\Exceptions\FeatureDependencyException;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Support\Demo;
use InvalidArgumentException;

/**
 * Which features this install has, and who decided.
 *
 * A key resolves in this order: a demo build has everything; a `core` key is always
 * on; otherwise the per-install override wins, else the plan's preset; and whatever
 * the outcome, a key is off while anything in its `requires` is off. config/features.php
 * is the only place keys, presets and dependencies are declared.
 *
 * Plan = the `tier` setting, overrides = the `feature_overrides` setting (JSON
 * `{key: bool}`, sparse: only deviations from the plan). Both are written here and
 * only here, by a super_admin (routes/web.php), and every write is audited.
 *
 * The resolved map is memoised for the request. Setting's saved/deleted hook calls
 * flush() whenever either setting changes, however it was written, so a plan or
 * override change is visible to the very next call.
 */
final class FeatureService
{
    public const SETTING_PLAN = 'tier';
    public const SETTING_OVERRIDES = 'feature_overrides';

    public const SOURCE_CORE = 'core';
    public const SOURCE_PLAN = 'plan';
    public const SOURCE_OVERRIDE = 'override';
    public const SOURCE_DEPENDENCY = 'dependency';
    public const SOURCE_DEMO = 'demo';

    private ?string $plan = null;

    /** @var array<string,bool>|null */
    private ?array $overrides = null;

    /** @var array<string,array{enabled:bool,wanted:bool,source:string,missing:list<string>}>|null */
    private ?array $resolved = null;

    public function flush(): void
    {
        $this->plan = $this->overrides = $this->resolved = null;
    }

    // --- Catalogue ------------------------------------------------------------

    /** @return array<string,array{group:string,core?:bool,requires?:list<string>}> */
    public function definitions(): array
    {
        return config('features.features', []);
    }

    public function isToggleable(string $key): bool
    {
        $def = $this->definitions()[$key] ?? null;

        return $def !== null && ! ($def['core'] ?? false);
    }

    /** @return list<string> */
    public function toggleable(): array
    {
        return array_values(array_filter(array_keys($this->definitions()), fn ($key) => $this->isToggleable($key)));
    }

    /**
     * The toggleable keys by group, in the configured group order. Groups with
     * nothing to toggle are left out.
     *
     * @return array<string,list<string>>
     */
    public function groups(): array
    {
        $byGroup = [];
        foreach ($this->toggleable() as $key) {
            $byGroup[$this->definitions()[$key]['group']][] = $key;
        }

        $ordered = [];
        foreach (array_merge(config('features.groups', []), array_keys($byGroup)) as $group) {
            if (isset($byGroup[$group])) {
                $ordered[$group] = $byGroup[$group];
            }
        }

        return $ordered;
    }

    public function label(string $key): string
    {
        return __('features.labels.'.str_replace('.', '_', $key));
    }

    public function groupLabel(string $group): string
    {
        return __("features.groups.{$group}");
    }

    // --- Plan -----------------------------------------------------------------

    /** @return list<string> */
    public function plans(): array
    {
        return array_keys(config('features.plans', []));
    }

    public function plan(): string
    {
        if ($this->plan === null) {
            $plan = (string) Setting::get(self::SETTING_PLAN, config('pos.default_tier'));

            // A hand-edited or half-migrated database must not lock the till down.
            $this->plan = in_array($plan, $this->plans(), true) ? $plan : (string) config('pos.default_tier');
        }

        return $this->plan;
    }

    /** @return array{max_users:?int,max_devices:?int,roles:list<string>} */
    public function limits(): array
    {
        return config('features.plans.'.$this->plan().'.limits', []);
    }

    /**
     * The toggleable keys a plan switches on. Keys the config no longer defines
     * (or that became core) are dropped rather than trusted.
     *
     * @return list<string>
     */
    public function preset(?string $plan = null): array
    {
        return array_values(array_filter(
            config('features.plans.'.($plan ?? $this->plan()).'.features', []),
            fn ($key) => $this->isToggleable($key),
        ));
    }

    // --- Overrides ------------------------------------------------------------

    /**
     * The stored overrides, minus anything stale: a key the config no longer has, one
     * that became core, or a value that is not a boolean. They are ignored here and
     * dropped for good the next time overrides are written.
     *
     * @return array<string,bool>
     */
    public function overrides(): array
    {
        if ($this->overrides === null) {
            $raw = Setting::get(self::SETTING_OVERRIDES, []);

            $this->overrides = is_array($raw)
                ? array_filter($raw, fn ($value, $key) => is_string($key) && is_bool($value) && $this->isToggleable($key), ARRAY_FILTER_USE_BOTH)
                : [];
        }

        return $this->overrides;
    }

    // --- Resolution -----------------------------------------------------------

    public function enabled(string $key): bool
    {
        if (! isset($this->definitions()[$key])) {
            return false;
        }

        return Demo::enabled() || $this->resolve()[$key]['enabled'];
    }

    /**
     * Every key's state. `enabled` is the outcome; `wanted` is what the plan or override
     * asked for before dependencies; `source` says who decided (core / plan / override /
     * dependency, or demo); `missing` lists the requirements that are off.
     *
     * @return array<string,array{enabled:bool,wanted:bool,source:string,missing:list<string>,core:bool}>
     */
    public function all(): array
    {
        $demo = Demo::enabled();
        $all = [];

        foreach ($this->resolve() as $key => $state) {
            $all[$key] = ($demo
                ? ['enabled' => true, 'wanted' => true, 'source' => self::SOURCE_DEMO, 'missing' => []]
                : $state) + ['core' => ! $this->isToggleable($key)];
        }

        return $all;
    }

    /**
     * What switching to $plan would turn on and off, keeping this install's overrides.
     * Compares what the till actually does, so it matches what the user will see.
     *
     * @return array{on:list<string>,off:list<string>}
     */
    public function preview(string $plan): array
    {
        $before = $this->resolve();
        $after = $this->compute($plan, $this->overrides());
        $diff = ['on' => [], 'off' => []];

        foreach ($this->toggleable() as $key) {
            if ($before[$key]['enabled'] !== $after[$key]['enabled']) {
                $diff[$after[$key]['enabled'] ? 'on' : 'off'][] = $key;
            }
        }

        return $diff;
    }

    /** @return array<string,array{enabled:bool,wanted:bool,source:string,missing:list<string>}> */
    private function resolve(): array
    {
        return $this->resolved ??= $this->compute($this->plan(), $this->overrides());
    }

    /**
     * @param  array<string,bool>  $overrides
     * @return array<string,array{enabled:bool,wanted:bool,source:string,missing:list<string>}>
     */
    private function compute(string $plan, array $overrides): array
    {
        $defs = $this->definitions();
        $preset = array_flip($this->preset($plan));
        $out = [];

        $visit = function (string $key) use (&$visit, &$out, $defs, $preset, $overrides): array {
            if (isset($out[$key])) {
                return $out[$key];
            }

            $def = $defs[$key] ?? null;
            if ($def === null) {
                return ['enabled' => false, 'wanted' => false, 'source' => self::SOURCE_PLAN, 'missing' => []];
            }
            if ($def['core'] ?? false) {
                return $out[$key] = ['enabled' => true, 'wanted' => true, 'source' => self::SOURCE_CORE, 'missing' => []];
            }

            // Placeholder so a requires-cycle in the config ends instead of recursing.
            $out[$key] = ['enabled' => false, 'wanted' => false, 'source' => self::SOURCE_PLAN, 'missing' => []];

            $overridden = array_key_exists($key, $overrides);
            $wanted = $overridden ? $overrides[$key] : isset($preset[$key]);
            $missing = array_values(array_filter($def['requires'] ?? [], fn ($required) => ! $visit($required)['enabled']));
            $blocked = $wanted && $missing !== [];

            return $out[$key] = [
                'enabled' => $wanted && ! $blocked,
                'wanted' => $wanted,
                'source' => $blocked ? self::SOURCE_DEPENDENCY : ($overridden ? self::SOURCE_OVERRIDE : self::SOURCE_PLAN),
                'missing' => $missing,
            ];
        };

        foreach (array_keys($defs) as $key) {
            $visit($key);
        }

        return $out;
    }

    // --- Changes (all audited) --------------------------------------------------

    public function setPlan(string $plan, ?int $actorId = null): void
    {
        if (! in_array($plan, $this->plans(), true)) {
            throw new InvalidArgumentException("Unknown plan '{$plan}'.");
        }

        $old = $this->plan();
        Setting::set(self::SETTING_PLAN, $plan, 'license');
        $this->flush();

        // Action and value shape predate feature overrides; the audit trail stays continuous.
        AuditLog::record($actorId, 'tier_change', null, null, ['tier' => $old], ['tier' => $plan]);
    }

    /**
     * Switch one feature on or off for this install, or `null` to follow the plan again.
     *
     * @throws FeatureDependencyException
     */
    public function override(string $key, ?bool $value, ?int $actorId = null): void
    {
        $this->sync([$key => $value], $actorId);
    }

    /**
     * Apply a desired on/off state for any number of keys at once (`null` = follow the
     * plan). Only keys whose state actually changes are touched, and a state equal to
     * the plan's is stored as "no override". Everything is checked against the
     * dependencies before anything is written.
     *
     * @param  array<string,?bool>  $desired
     * @throws FeatureDependencyException
     * @throws InvalidArgumentException  a key that is unknown or core
     */
    public function sync(array $desired, ?int $actorId = null): void
    {
        $inPlan = array_flip($this->preset());
        $before = $this->resolve();
        $old = $this->overrides();
        $new = $old;

        foreach ($desired as $key => $want) {
            if (! $this->isToggleable($key)) {
                throw new InvalidArgumentException("'{$key}' is not a toggleable feature.");
            }
            if ($want === null || (bool) $want === isset($inPlan[$key])) {
                unset($new[$key]);
            } elseif ((bool) $want !== $before[$key]['wanted']) {
                $new[$key] = (bool) $want;
            }
        }

        $this->assertConsistent($old, $new);
        $this->store($old, $new, $actorId, 'feature_override_change');
    }

    /** Drop every override: the install follows its plan exactly. */
    public function reset(?int $actorId = null): void
    {
        $old = $this->overrides();
        if ($old === []) {
            return;
        }

        $this->assertConsistent($old, []);
        $this->store($old, [], $actorId, 'feature_overrides_reset');
    }

    /**
     * Refuse a change that leaves something switched on without what it needs, or that
     * switches off what other working features need. Only what this change causes is
     * reported, so a problem that was already there never blocks an unrelated edit.
     *
     * @param  array<string,bool>  $old
     * @param  array<string,bool>  $new
     * @throws FeatureDependencyException
     */
    private function assertConsistent(array $old, array $new): void
    {
        $plan = $this->plan();
        $before = $this->compute($plan, $old);
        $after = $this->compute($plan, $new);

        $changed = array_filter($this->toggleable(), fn ($key) => $before[$key]['wanted'] !== $after[$key]['wanted']);

        // Switched on, but something it needs is off.
        $unmet = [];
        foreach ($changed as $key) {
            if ($after[$key]['wanted'] && $after[$key]['missing'] !== []) {
                $unmet[$key] = $after[$key]['missing'];
            }
        }

        // Switched off, and something that was working needs it.
        $turnedOff = array_values(array_filter($changed, fn ($key) => ! $after[$key]['wanted']));
        $broken = array_values(array_filter(
            $this->toggleable(),
            fn ($key) => $before[$key]['enabled'] && ! $after[$key]['enabled'] && ! in_array($key, $changed, true),
        ));

        $messages = [];
        foreach ($unmet as $key => $missing) {
            $messages[] = __('features.errors.requires', ['feature' => $this->label($key), 'missing' => $this->labels($missing)]);
        }
        if ($broken !== []) {
            $messages[] = __('features.errors.required_by', ['feature' => $this->labels($turnedOff), 'dependents' => $this->labels($broken)]);
        }

        if ($messages !== []) {
            throw new FeatureDependencyException(implode(' ', $messages), $unmet, $broken);
        }
    }

    /**
     * @param  array<string,bool>  $old
     * @param  array<string,bool>  $new
     */
    private function store(array $old, array $new, ?int $actorId, string $action): void
    {
        $raw = Setting::get(self::SETTING_OVERRIDES, []);

        // Nothing changed. If the stored value still carries stale keys, tidy it quietly.
        if ($old === $new) {
            if ($raw !== $new) {
                Setting::set(self::SETTING_OVERRIDES, $new, 'license', 'json');
                $this->flush();
            }

            return;
        }

        Setting::set(self::SETTING_OVERRIDES, $new, 'license', 'json');
        $this->flush();

        AuditLog::record($actorId, $action, null, null, ['feature_overrides' => $old], ['feature_overrides' => $new]);
    }

    /** @param  list<string>  $keys */
    private function labels(array $keys): string
    {
        return implode(__('features.separator'), array_map(fn ($key) => $this->label($key), $keys));
    }
}
