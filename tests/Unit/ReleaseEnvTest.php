<?php

namespace Tests\Unit;

use App\Support\ReleaseEnv;
use PHPUnit\Framework\TestCase;

/**
 * The gate that stops a debug build reaching a client (AppServiceProvider hooks
 * it onto native:build). If this decision ever quietly inverts, the next
 * release ships a stack trace to the till, so it gets pinned down here.
 */
class ReleaseEnvTest extends TestCase
{
    private const SHIPPABLE = [
        'APP_ENV' => 'production',
        'APP_DEBUG' => 'false',
        'APP_KEY' => 'base64:notarealkey',
        'POS_DEMO_MODE' => 'false',
    ];

    public function test_a_production_env_is_shippable(): void
    {
        $this->assertSame([], ReleaseEnv::problemsIn(self::SHIPPABLE));
    }

    /** @dataProvider unshippable */
    public function test_it_refuses(array $overrides, string $expected): void
    {
        $problems = ReleaseEnv::problemsIn(array_merge(self::SHIPPABLE, $overrides));

        $this->assertNotEmpty($problems, 'expected a refusal');
        $this->assertStringContainsString($expected, implode(' ', $problems));
    }

    public static function unshippable(): array
    {
        return [
            'local env'      => [['APP_ENV' => 'local'], 'APP_ENV'],
            'debug on'       => [['APP_DEBUG' => 'true'], 'APP_DEBUG'],
            'debug on as 1'  => [['APP_DEBUG' => '1'], 'APP_DEBUG'],
            'no key'         => [['APP_KEY' => ''], 'APP_KEY'],
            'missing key'    => [['APP_KEY' => null], 'APP_KEY'],
        ];
    }

    public function test_an_unreadable_env_is_refused_rather_than_assumed_fine(): void
    {
        // ReleaseEnv::read() returns [] when .env is missing. Absence has to
        // read as "not shippable", or the guard fails open.
        $this->assertNotEmpty(ReleaseEnv::problemsIn([]));
    }

    public function test_an_unexpected_demo_build_is_refused(): void
    {
        $problems = ReleaseEnv::problemsIn(
            array_merge(self::SHIPPABLE, ['POS_DEMO_MODE' => 'true']),
            expectDemo: false,
        );

        $this->assertStringContainsString('POS_DEMO_MODE', implode(' ', $problems));
    }

    public function test_a_demo_build_is_shippable_when_that_is_what_was_asked_for(): void
    {
        $this->assertSame([], ReleaseEnv::problemsIn(
            array_merge(self::SHIPPABLE, ['POS_DEMO_MODE' => 'true']),
            expectDemo: true,
        ));
    }

    public function test_the_demo_flag_is_ignored_when_no_intent_is_stated(): void
    {
        // native:build does not know which kind of build you meant, so it must
        // not refuse a legitimate demo build.
        $this->assertSame([], ReleaseEnv::problemsIn(
            array_merge(self::SHIPPABLE, ['POS_DEMO_MODE' => 'true']),
        ));
    }
}
