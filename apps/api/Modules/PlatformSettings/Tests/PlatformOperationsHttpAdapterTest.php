<?php

namespace Modules\PlatformSettings\Tests;

use App\Integrations\PlatformSettings\PlatformSettingsApi;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Modules\PlatformSettings\Contracts\BackupOperationsGateway;
use Modules\PlatformSettings\Contracts\PlatformHealthGateway;
use Modules\PlatformSettings\Domain\BackupStatus;
use Modules\PlatformSettings\Domain\HealthCheckResult;
use Modules\PlatformSettings\Features\Operations\Handler\PlatformOperationsHandler;
use Modules\PlatformSettings\Features\Operations\Http\DispatchBackupController;
use Modules\PlatformSettings\Features\Operations\Http\GetPlatformOverviewController;
use Tests\TestCase;

final class PlatformOperationsHttpAdapterTest extends TestCase
{
    use RefreshDatabase;

    private const CORRELATION_ID = '0197f0e0-0000-7000-8000-000000000821';

    public function test_overview_returns_a_partial_degraded_snapshot_when_backup_source_is_unavailable(): void
    {
        $response = (new GetPlatformOverviewController($this->api(), $this->operations(true)))(
            $this->request('GET', '/platform-operations/overview'),
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('degraded', $response->getData(true)['status']);
        $this->assertNotEmpty($response->getData(true)['issues']);
        $this->assertArrayHasKey('allowed_actions', $response->getData(true));
    }

    public function test_backup_request_is_accepted_as_an_asynchronous_idempotent_operation(): void
    {
        $request = $this->request('POST', '/platform-operations/backups');
        $request->headers->set('Idempotency-Key', 'backup-request-1');
        $response = (new DispatchBackupController($this->api(), $this->operations(false)))($request);

        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame('requested', $response->getData(true)['status']);
        $this->assertNotEmpty($response->getData(true)['operation_id']);
    }

    private function api(): PlatformSettingsApi
    {
        return new PlatformSettingsApi(new PlatformSettingsHttpPrincipalResolver, new PlatformSettingsHttpDecider(false));
    }

    private function operations(bool $failBackups): PlatformOperationsHandler
    {
        return new PlatformOperationsHandler(
            new PlatformSettingsHttpHealthGateway,
            new PlatformSettingsHttpBackupGateway($failBackups),
        );
    }

    private function request(string $method, string $uri): Request
    {
        $request = Request::create($uri, $method);
        $request->headers->set('X-Correlation-ID', self::CORRELATION_ID);
        $request->headers->set('Authorization', 'allow');

        return $request;
    }
}

final class PlatformSettingsHttpHealthGateway implements PlatformHealthGateway
{
    public function snapshot(): array
    {
        return [new HealthCheckResult('database', 'healthy', new DateTimeImmutable('2026-07-23T00:00:00Z'), 8, 'database_healthy')];
    }
}

final class PlatformSettingsHttpBackupGateway implements BackupOperationsGateway
{
    public function __construct(private readonly bool $fail) {}

    public function status(): BackupStatus
    {
        if ($this->fail) {
            throw new \RuntimeException('backup source unavailable');
        }

        return new BackupStatus('healthy', new DateTimeImmutable('2026-07-23T00:00:00Z'), null, new DateTimeImmutable('2026-07-23T00:00:00Z'));
    }

    public function requestBackup(string $operationId): void {}

    public function requestRestoreValidation(string $operationId, string $backupId): void {}
}
