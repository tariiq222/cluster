<?php

namespace Modules\PlatformSettings\Tests;

use App\Integrations\PlatformOperations\LaravelPlatformHealthGateway;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Modules\PlatformSettings\Contracts\BackupOperationsGateway;
use Modules\PlatformSettings\Contracts\PlatformHealthGateway;
use Modules\PlatformSettings\Domain\BackupStatus;
use Modules\PlatformSettings\Domain\HealthCheckResult;
use Modules\PlatformSettings\Features\Operations\Handler\PlatformOperationsHandler;
use RuntimeException;
use Tests\TestCase;

final class PlatformHealthHandlerTest extends TestCase
{
    public function test_health_snapshot_is_healthy_when_every_check_is_healthy(): void
    {
        $handler = new PlatformOperationsHandler(new FakeHealthGateway([
            new HealthCheckResult('database', 'healthy', new DateTimeImmutable('2026-07-23T08:00:00+03:00'), 8, 'reachable'),
            new HealthCheckResult('redis', 'healthy', new DateTimeImmutable('2026-07-23T08:00:00+03:00'), 4, 'reachable'),
        ]), new HealthTestBackupGateway);

        $snapshot = $handler->health();

        $this->assertSame('healthy', $snapshot->status);
        $this->assertSame(['database', 'redis'], array_map(static fn (HealthCheckResult $check): string => $check->code, $snapshot->checks));
        $this->assertSame('reachable', $snapshot->checks[0]->messageCode);
    }

    public function test_health_snapshot_is_degraded_when_redis_times_out(): void
    {
        $handler = new PlatformOperationsHandler(new FakeHealthGateway([
            new HealthCheckResult('database', 'healthy', new DateTimeImmutable('2026-07-23T08:00:00+03:00'), 8, 'reachable'),
            new HealthCheckResult('redis', 'degraded', new DateTimeImmutable('2026-07-23T08:00:00+03:00'), 250, 'timeout'),
        ]), new HealthTestBackupGateway);

        $snapshot = $handler->health();

        $this->assertSame('degraded', $snapshot->status);
        $this->assertSame('timeout', $snapshot->checks[1]->messageCode);
    }

    public function test_health_snapshot_rejects_a_sensitive_message(): void
    {
        $handler = new PlatformOperationsHandler(new FakeHealthGateway([
            new HealthCheckResult('database', 'unhealthy', new DateTimeImmutable('2026-07-23T08:00:00+03:00'), 8, 'mysql://admin:password@database/app'),
        ]), new HealthTestBackupGateway);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Health check messages must be safe codes.');

        $handler->health();
    }

    public function test_adapter_enforces_a_real_deadline_around_a_slow_probe_and_returns_only_timeout_code(): void
    {
        config(['platform_operations.health.timeout_ms' => 1000]);
        $gateway = new LaravelPlatformHealthGateway(new HealthTestBackupGateway, [
            'redis' => static function (int $timeoutMs): string {
                usleep(2_000_000);

                return 'reachable';
            },
        ]);
        $startedAt = hrtime(true);

        $check = $gateway->snapshot()[0];
        $elapsedMs = (int) floor((hrtime(true) - $startedAt) / 1_000_000);

        $this->assertSame('degraded', $check->status);
        $this->assertSame('timeout', $check->messageCode);
        $this->assertLessThan(1800, $elapsedMs);
        $this->assertStringNotContainsString('password', $check->messageCode);
    }

    public function test_default_database_probe_is_wrapped_by_the_same_real_deadline(): void
    {
        config(['platform_operations.health.timeout_ms' => 1000]);
        $originalDatabase = DB::getFacadeRoot();
        DB::swap(new class
        {
            public function connection(): object
            {
                return new class
                {
                    public function getPdo(): void
                    {
                        usleep(2_000_000);
                    }
                };
            }
        });
        $gateway = new LaravelPlatformHealthGateway(new HealthTestBackupGateway);
        $defaults = (new \ReflectionMethod($gateway, 'defaultProbes'))->invoke($gateway);
        $startedAt = hrtime(true);

        try {
            $check = (new \ReflectionMethod($gateway, 'check'))->invoke($gateway, 'database', $defaults['database']);
        } finally {
            DB::swap($originalDatabase);
        }

        $elapsedMs = (int) floor((hrtime(true) - $startedAt) / 1_000_000);
        $this->assertSame('degraded', $check->status);
        $this->assertSame('timeout', $check->messageCode);
        $this->assertLessThan(1800, $elapsedMs);
    }
}

final readonly class FakeHealthGateway implements PlatformHealthGateway
{
    /** @param list<HealthCheckResult> $checks */
    public function __construct(private array $checks) {}

    public function snapshot(): array
    {
        return $this->checks;
    }
}

final readonly class HealthTestBackupGateway implements BackupOperationsGateway
{
    public function status(): BackupStatus
    {
        return new BackupStatus('available', null, null, null);
    }

    public function requestBackup(string $operationId): void {}

    public function requestRestoreValidation(string $operationId, string $backupId): void {}
}
