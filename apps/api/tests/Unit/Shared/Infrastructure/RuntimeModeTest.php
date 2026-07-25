<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Infrastructure;

use PHPUnit\Framework\TestCase;
use Shared\Infrastructure\RuntimeMode;

/**
 * @internal
 *
 * Covers the resolution rules of {@see RuntimeMode::fromConfig()}. The class
 * is the single source of truth for production-vs-test detection across
 * `AppServiceProvider`, so every transition must be tested directly here
 * rather than indirectly through the service provider.
 */
final class RuntimeModeTest extends TestCase
{
    public function test_explicit_production_override_wins(): void
    {
        $mode = RuntimeMode::fromConfig('production', 'local');

        self::assertTrue($mode->isProduction());
        self::assertFalse($mode->isTesting());
        self::assertFalse($mode->isLocal());
    }

    public function test_explicit_testing_override_wins(): void
    {
        $mode = RuntimeMode::fromConfig('testing', 'production');

        self::assertTrue($mode->isTesting());
        self::assertFalse($mode->isProduction());
        self::assertFalse($mode->isLocal());
    }

    public function test_explicit_local_override_wins(): void
    {
        $mode = RuntimeMode::fromConfig('local', 'testing');

        self::assertTrue($mode->isLocal());
        self::assertFalse($mode->isProduction());
        self::assertFalse($mode->isTesting());
    }

    public function test_falls_back_to_production_when_override_missing(): void
    {
        $mode = RuntimeMode::fromConfig(null, 'production');

        self::assertTrue($mode->isProduction());
    }

    public function test_falls_back_to_testing_when_override_missing(): void
    {
        $mode = RuntimeMode::fromConfig(null, 'testing');

        self::assertTrue($mode->isTesting());
    }

    public function test_unknown_override_and_fallback_collapses_to_local(): void
    {
        $mode = RuntimeMode::fromConfig('staging', 'staging');

        self::assertTrue($mode->isLocal());
    }

    public function test_empty_override_uses_fallback(): void
    {
        $mode = RuntimeMode::fromConfig('', 'production');

        self::assertTrue($mode->isProduction());
    }

    public function test_case_insensitive_resolution(): void
    {
        self::assertTrue(RuntimeMode::fromConfig('PRODUCTION', null)->isProduction());
        self::assertTrue(RuntimeMode::fromConfig('Testing', null)->isTesting());
        self::assertTrue(RuntimeMode::fromConfig('LOCAL', null)->isLocal());
    }

    public function test_prod_alias_resolves_to_production(): void
    {
        self::assertTrue(RuntimeMode::fromConfig('prod', null)->isProduction());
    }

    public function test_test_alias_resolves_to_testing(): void
    {
        self::assertTrue(RuntimeMode::fromConfig('test', null)->isTesting());
    }

    public function test_no_arguments_collapses_to_local(): void
    {
        self::assertTrue(RuntimeMode::fromConfig(null, null)->isLocal());
    }
}
