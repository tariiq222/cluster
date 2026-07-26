<?php

namespace Modules\PlatformSettings\Tests;

if (! class_exists('Modules\\PlatformSettings\\Tests\\OperationsAccessDecision')) {
    class_alias(\Modules\Authorization\Contracts\AccessDecision::class, 'Modules\\PlatformSettings\\Tests\\OperationsAccessDecision');
}
if (! class_exists('Modules\\PlatformSettings\\Tests\\OperationsDecideAccess')) {
    class_alias(\Modules\Authorization\Contracts\DecideAccess::class, 'Modules\\PlatformSettings\\Tests\\OperationsDecideAccess');
}
if (! class_exists('Modules\\PlatformSettings\\Tests\\OperationsRecordFacts')) {
    class_alias(\Modules\Authorization\Contracts\RecordFacts::class, 'Modules\\PlatformSettings\\Tests\\OperationsRecordFacts');
}
if (! class_exists('Modules\\PlatformSettings\\Tests\\OperationsResolvePrincipal')) {
    class_alias(\Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal::class, 'Modules\\PlatformSettings\\Tests\\OperationsResolvePrincipal');
}

use App\Integrations\PlatformSettings\PlatformSettingsApi;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\PlatformSettings\Contracts\BackupOperationsGateway;
use Modules\PlatformSettings\Contracts\PlatformHealthGateway;
use Modules\PlatformSettings\Contracts\TechnicalLogArchive;
use Modules\PlatformSettings\Contracts\TechnicalLogSource;
use Modules\PlatformSettings\Domain\ArchiveBatch;
use Modules\PlatformSettings\Domain\ArchiveManifest;
use Modules\PlatformSettings\Domain\BackupStatus;
use Modules\PlatformSettings\Domain\HealthCheckResult;
use Modules\PlatformSettings\Domain\TechnicalLogFilter;
use Modules\PlatformSettings\Domain\TechnicalLogPage;
use Modules\PlatformSettings\Features\Alerts\Http\AlertPoliciesController;
use Modules\PlatformSettings\Features\Logs\Handler\TechnicalLogsHandler;
use Modules\PlatformSettings\Features\Logs\Http\TechnicalLogsController;
use Modules\PlatformSettings\Features\Maintenance\Handler\MaintenanceWindowHandler;
use Modules\PlatformSettings\Features\Maintenance\Http\MaintenanceWindowsController;
use Modules\PlatformSettings\Features\Operations\Handler\PlatformOperationsHandler;
use Modules\PlatformSettings\Features\Operations\Http\DispatchBackupController;
use Modules\PlatformSettings\Features\Operations\Http\GetPlatformOverviewController;
use Modules\PlatformSettings\Infrastructure\Outbox\PlatformSettingsOutbox;
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

    public function test_dispatch_backup_returns_403_when_the_run_capability_is_denied(): void
    {
        $request = $this->request('POST', '/platform-operations/backups');
        $request->headers->set('Idempotency-Key', 'backup-deny-test');
        $response = (new DispatchBackupController($this->denyApi(), $this->operations(false)))($request);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('https://cluster.example/problems/access-denied', $response->getData(true)['type']);
        $this->assertSame(0, DB::table('platform_operation_requests')->count());
    }

    public function test_dispatch_backup_returns_401_when_no_session_principal_is_resolvable(): void
    {
        $request = Request::create('/platform-operations/backups', 'POST');
        $request->headers->set('X-Correlation-ID', self::CORRELATION_ID);
        $request->headers->set('Authorization', 'missing');
        $request->headers->set('Idempotency-Key', 'backup-no-session');
        $response = (new DispatchBackupController($this->api(), $this->operations(false)))($request);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('https://cluster.example/problems/authentication-required', $response->getData(true)['type']);
    }

    public function test_maintenance_index_returns_403_when_the_manage_capability_is_denied(): void
    {
        $controller = new MaintenanceWindowsController($this->denyApi(), $this->maintenance());
        $response = $controller->index($this->request('GET', '/platform-operations/maintenance-windows'));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('https://cluster.example/problems/access-denied', $response->getData(true)['type']);
    }

    public function test_maintenance_schedule_returns_401_when_no_session_principal_is_resolvable(): void
    {
        $controller = new MaintenanceWindowsController($this->api(), $this->maintenance());
        $request = Request::create('/platform-operations/maintenance-windows', 'POST');
        $request->headers->set('X-Correlation-ID', self::CORRELATION_ID);
        $request->headers->set('Authorization', 'missing');
        $request->headers->set('Idempotency-Key', 'maintenance-no-session');
        $request->merge([
            'starts_at' => '2026-07-23T10:00:00+03:00',
            'ends_at' => '2026-07-23T11:00:00+03:00',
            'message_ar' => 'صيانة',
            'message_en' => 'maintenance',
        ]);

        $response = $controller->store($request);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('https://cluster.example/problems/authentication-required', $response->getData(true)['type']);
        $this->assertSame(0, DB::table('platform_maintenance_windows')->count());
    }

    public function test_maintenance_cancel_requires_idempotency_key(): void
    {
        $windowId = '0197f0e0-0000-7000-8000-000000000830';
        DB::table('platform_maintenance_windows')->insert([
            'id' => $windowId,
            'status' => 'scheduled',
            'reason' => json_encode(['ar' => 'صيانة', 'en' => 'maintenance']),
            'starts_at' => '2026-07-23 10:00:00',
            'ends_at' => '2026-07-23 11:00:00',
            'created_by' => '0197f0e0-0000-7000-8000-000000000821',
            'lock_version' => 1,
            'created_at' => '2026-07-23 09:00:00',
            'updated_at' => '2026-07-23 09:00:00',
        ]);
        $request = $this->request('POST', '/platform-operations/maintenance-windows/'.$windowId.'/cancel');
        $request->headers->set('If-Match', '"1"');
        $response = (new MaintenanceWindowsController($this->api(), $this->maintenance()))->cancel($request, $windowId);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('https://cluster.example/problems/invalid-idempotency-key', $response->getData(true)['type']);
        $this->assertSame('scheduled', DB::table('platform_maintenance_windows')->where('id', $windowId)->value('status'));
    }

    public function test_maintenance_cancel_replays_and_rejects_conflicting_key_reuse(): void
    {
        $windowId = '0197f0e0-0000-7000-8000-000000000832';
        DB::table('platform_maintenance_windows')->insert([
            'id' => $windowId,
            'status' => 'scheduled',
            'reason' => json_encode(['ar' => 'صيانة', 'en' => 'maintenance']),
            'starts_at' => '2026-07-23 10:00:00',
            'ends_at' => '2026-07-23 11:00:00',
            'created_by' => '0197f0e0-0000-7000-8000-000000000821',
            'lock_version' => 1,
            'created_at' => '2026-07-23 09:00:00',
            'updated_at' => '2026-07-23 09:00:00',
        ]);
        $controller = new MaintenanceWindowsController($this->api(), $this->maintenance());
        $request = $this->request('POST', '/platform-operations/maintenance-windows/'.$windowId.'/cancel');
        $request->headers->set('If-Match', '"1"');
        $request->headers->set('Idempotency-Key', 'maintenance-cancel-replay');
        $first = $controller->cancel($request, $windowId);
        $replay = $controller->cancel($request, $windowId);
        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame(200, $replay->getStatusCode());
        $this->assertSame($first->getData(true), $replay->getData(true));
        $conflict = clone $request;
        $conflict->headers->set('If-Match', '"2"');
        $response = $controller->cancel($conflict, $windowId);
        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('https://cluster.example/problems/idempotency-conflict', $response->getData(true)['type']);
    }

    public function test_maintenance_cancel_returns_412_when_lock_version_is_stale(): void
    {
        DB::table('platform_maintenance_windows')->insert([
            'id' => '0197f0e0-0000-7000-8000-000000000831',
            'status' => 'scheduled',
            'reason' => json_encode(['ar' => 'صيانة', 'en' => 'maintenance']),
            'starts_at' => '2026-07-23 10:00:00',
            'ends_at' => '2026-07-23 11:00:00',
            'created_by' => '0197f0e0-0000-7000-8000-000000000821',
            'lock_version' => 1,
            'created_at' => '2026-07-23 09:00:00',
            'updated_at' => '2026-07-23 09:00:00',
        ]);

        $controller = new MaintenanceWindowsController($this->api(), $this->maintenance());
        $request = $this->request('POST', '/platform-operations/maintenance-windows/0197f0e0-0000-7000-8000-000000000831/cancel');
        $request->headers->set('If-Match', '"99"');
        $request->headers->set('Idempotency-Key', 'maintenance-cancel-stale');

        $response = $controller->cancel($request, '0197f0e0-0000-7000-8000-000000000831');

        $this->assertSame(412, $response->getStatusCode());
        $this->assertSame('https://cluster.example/problems/precondition-failed', $response->getData(true)['type']);
        $this->assertSame('scheduled', DB::table('platform_maintenance_windows')->where('id', '0197f0e0-0000-7000-8000-000000000831')->value('status'));
    }

    public function test_alert_policy_index_returns_403_when_the_manage_capability_is_denied(): void
    {
        $controller = new AlertPoliciesController($this->denyApi());
        $response = $controller->index($this->request('GET', '/platform-operations/alert-policies'));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('https://cluster.example/problems/access-denied', $response->getData(true)['type']);
    }

    public function test_alert_policy_update_returns_404_when_policy_does_not_exist(): void
    {
        $controller = new AlertPoliciesController($this->api());
        $request = $this->request('PATCH', '/api/v1/platform-operations/alert-policies/0197f0e0-0000-7000-8000-000000000899');
        $request->headers->set('If-Match', '"1"');
        $request->merge(['enabled' => false]);

        $response = $controller->update($request, '0197f0e0-0000-7000-8000-000000000899');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('https://cluster.example/problems/resource-not-found', $response->getData(true)['type']);
    }

    public function test_technical_logs_index_returns_403_when_the_read_capability_is_denied(): void
    {
        $controller = new TechnicalLogsController($this->denyApi(), $this->logs());
        $response = $controller->index($this->request('GET', '/platform-operations/technical-logs'));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('https://cluster.example/problems/access-denied', $response->getData(true)['type']);
    }

    public function test_technical_logs_index_returns_401_when_no_session_principal_is_resolvable(): void
    {
        $controller = new TechnicalLogsController($this->api(), $this->logs());
        $request = Request::create('/platform-operations/technical-logs', 'GET');
        $request->headers->set('X-Correlation-ID', self::CORRELATION_ID);
        $request->headers->set('Authorization', 'missing');

        $response = $controller->index($request);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('https://cluster.example/problems/authentication-required', $response->getData(true)['type']);
    }

    private function api(): PlatformSettingsApi
    {
        return new PlatformSettingsApi(new PlatformOperationsHttpPrincipalResolver, new PlatformOperationsHttpDecider(false));
    }

    private function denyApi(): PlatformSettingsApi
    {
        return new PlatformSettingsApi(new PlatformOperationsHttpPrincipalResolver, new PlatformOperationsHttpDecider(true));
    }

    private function operations(bool $failBackups): PlatformOperationsHandler
    {
        return new PlatformOperationsHandler(
            new PlatformOperationsHttpHealthGateway,
            new PlatformOperationsHttpBackupGateway($failBackups),
            $this->app->make(PlatformSettingsOutbox::class),
        );
    }

    private function maintenance(): MaintenanceWindowHandler
    {
        return new MaintenanceWindowHandler;
    }

    private function logs(): TechnicalLogsHandler
    {
        return new TechnicalLogsHandler(new PlatformOperationsHttpTechnicalLogSource, new PlatformOperationsHttpTechnicalLogArchive);
    }

    private function request(string $method, string $uri): Request
    {
        $request = Request::create($uri, $method);
        $request->headers->set('X-Correlation-ID', self::CORRELATION_ID);
        $request->headers->set('Authorization', 'allow');

        return $request;
    }
}

final class PlatformOperationsHttpHealthGateway implements PlatformHealthGateway
{
    public function snapshot(): array
    {
        return [new HealthCheckResult('database', 'healthy', new DateTimeImmutable('2026-07-23T00:00:00Z'), 8, 'database_healthy')];
    }
}

final class PlatformOperationsHttpBackupGateway implements BackupOperationsGateway
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

final class PlatformOperationsHttpPrincipalResolver implements OperationsResolvePrincipal
{
    public function issue(array $principal): array
    {
        return ['access_token' => 'test', 'expires_at' => now()->addHour()->toIso8601String()];
    }

    public function resolve(Request $request): ?array
    {
        if ($request->header('Authorization') === 'missing') {
            return null;
        }

        return ['user_id' => '0197f0e0-0000-7000-8000-000000000821', 'facility_id' => '0197f0e0-0000-7000-8000-000000000822'];
    }
}

final class PlatformOperationsHttpDecider implements OperationsDecideAccess
{
    public function __construct(private readonly bool $deny) {}

    public function decide(array $actor, string $capability, ?OperationsRecordFacts $facts): OperationsAccessDecision
    {
        return new OperationsAccessDecision(
            $this->deny ? 'deny' : 'allow',
            $capability,
            ($facts === null ? 'platform_settings' : $facts->resourceType),
            [],
            'test',
            'test',
            'internal',
            allowedActions: $this->deny ? [] : ['create', 'update', 'validate', 'publish'],
        );
    }
}

final class PlatformOperationsHttpTechnicalLogSource implements TechnicalLogSource
{
    public function isAvailable(): bool
    {
        return true;
    }

    public function search(TechnicalLogFilter $filter): TechnicalLogPage
    {
        return new TechnicalLogPage([], null);
    }
}

final class PlatformOperationsHttpTechnicalLogArchive implements TechnicalLogArchive
{
    public function archive(ArchiveBatch $batch): ArchiveManifest
    {
        return new ArchiveManifest('0197f0e0-0000-7000-8000-000000000823', 0, new DateTimeImmutable('2026-07-23T00:00:00Z'), new DateTimeImmutable('2026-07-23T00:00:00Z'), str_repeat('a', 64), 'fixture-reference', 'fixture-manifest');
    }

    public function requestRestore(string $manifestId, string $actorId, string $reason): string
    {
        return '0197f0e0-0000-7000-8000-000000000824';
    }
}
