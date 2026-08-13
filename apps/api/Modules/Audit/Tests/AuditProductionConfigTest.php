<?php

declare(strict_types=1);

namespace Modules\Audit\Tests;

use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class AuditProductionConfigTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $originalEnvironment = [];

    public function test_production_accepts_a_valid_integrity_key_ring_and_retention_floor(): void
    {
        $this->setEnvironmentVariable('APP_ENV', 'production');
        $this->setEnvironmentVariable('AUDIT_INTEGRITY_KEYS', 'v1:'.str_repeat('a', 32).',v2:'.str_repeat('b', 32));
        $this->setEnvironmentVariable('AUDIT_INTEGRITY_KEY_VERSION', 'v2');
        $this->setEnvironmentVariable('AUDIT_RETENTION_DAYS', '2555');

        $config = require dirname(__DIR__, 3).'/config/audit.php';

        $this->assertSame(['v1' => str_repeat('a', 32), 'v2' => str_repeat('b', 32)], $config['integrity']['keys']);
        $this->assertSame('v2', $config['integrity']['active_key_version']);
        $this->assertSame(2555, $config['retention']['floor_days']);
    }

    public function test_production_fails_closed_when_the_integrity_key_ring_is_missing(): void
    {
        $this->setEnvironmentVariable('APP_ENV', 'production');
        $this->setEnvironmentVariable('AUDIT_INTEGRITY_KEYS', '');
        $this->setEnvironmentVariable('AUDIT_INTEGRITY_KEY_VERSION', '');

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('audit_integrity_runtime_unavailable');

        require dirname(__DIR__, 3).'/config/audit.php';
    }

    public function test_production_fails_closed_when_the_active_version_is_not_in_the_key_ring(): void
    {
        $this->setEnvironmentVariable('APP_ENV', 'production');
        $this->setEnvironmentVariable('AUDIT_INTEGRITY_KEYS', 'v1:'.str_repeat('a', 32));
        $this->setEnvironmentVariable('AUDIT_INTEGRITY_KEY_VERSION', 'v2');

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('audit_integrity_runtime_unavailable');

        require dirname(__DIR__, 3).'/config/audit.php';
    }

    public function test_legacy_revoke_mode_is_rejected_because_retention_requires_controlled_deletes(): void
    {
        $this->setEnvironmentVariable('APP_ENV', 'production');
        $this->setEnvironmentVariable('AUDIT_INTEGRITY_KEYS', 'v1:'.str_repeat('a', 32));
        $this->setEnvironmentVariable('AUDIT_INTEGRITY_KEY_VERSION', 'v1');
        $this->setEnvironmentVariable('AUDIT_ENFORCE_REVOKE', 'true');

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('audit_revoke_mode_incompatible_with_retention');

        require dirname(__DIR__, 3).'/config/audit.php';
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnvironment as $name => $value) {
            if ($value === false) {
                unset($_ENV[$name], $_SERVER[$name]);
                putenv($name);
            } else {
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
                putenv("{$name}={$value}");
            }
        }

        parent::tearDown();
    }

    private function setEnvironmentVariable(string $name, string $value): void
    {
        if (! array_key_exists($name, $this->originalEnvironment)) {
            $this->originalEnvironment[$name] = getenv($name);
        }

        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
        putenv("{$name}={$value}");
    }
}
