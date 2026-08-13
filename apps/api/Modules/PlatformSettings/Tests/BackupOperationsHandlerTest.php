<?php

namespace Modules\PlatformSettings\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\PlatformSettings\Contracts\BackupOperationsGateway;
use Modules\PlatformSettings\Contracts\PlatformHealthGateway;
use Modules\PlatformSettings\Domain\BackupStatus;
use Modules\PlatformSettings\Features\Operations\Handler\PlatformOperationsDispatchHandler;
use Modules\PlatformSettings\Features\Operations\Handler\PlatformOperationsHandler;
use Modules\PlatformSettings\Infrastructure\Outbox\PlatformSettingsOutbox;
use RuntimeException;
use Shared\Contracts\OutboxDuplicatePolicy;
use Shared\Contracts\TransactionalOutboxEnvelope;
use Tests\TestCase;

final class BackupOperationsHandlerTest extends TestCase
{
    use RefreshDatabase;

    public function test_unconfigured_backup_runtime_rejects_requests_without_queueing_them(): void
    {
        $gateway = new FakeBackupOperationsGateway;
        $gateway->available = false;
        $handler = $this->handler($gateway);

        try {
            $handler->requestBackup('0197f0e0-0000-7000-8000-000000000001', 'unavailable-runtime');
            $this->fail('Expected the unavailable backup runtime to reject the request.');
        } catch (\DomainException $exception) {
            $this->assertSame('platform_operations_unavailable', $exception->getMessage());
        }

        $this->assertDatabaseCount('platform_operation_requests', 0);
    }

    public function test_replaying_an_idempotency_key_returns_the_same_operation_without_starting_a_second_backup(): void
    {
        $gateway = new FakeBackupOperationsGateway;
        $handler = $this->handler($gateway);

        $first = $handler->requestBackup('0197f0e0-0000-7000-8000-000000000001', 'backup-once');
        $second = $handler->requestBackup('0197f0e0-0000-7000-8000-000000000001', 'backup-once');

        $this->assertSame(202, $first['http_status']);
        $this->assertSame($first['operation_id'], $second['operation_id']);
        $this->assertSame([], $gateway->backupOperationIds);
        $this->assertDatabaseCount('platform_operation_requests', 1);
        $this->assertSame(1, $this->dispatcher($gateway)->run(10));
        $this->assertSame([$first['operation_id']], $gateway->backupOperationIds);
    }

    public function test_restore_confirmation_requires_a_second_actor_and_separate_capabilities(): void
    {
        $gateway = new FakeBackupOperationsGateway;
        $handler = $this->handler($gateway);
        $requestedBy = '0197f0e0-0000-7000-8000-000000000001';
        $operation = $handler->requestRestore(
            requestedBy: $requestedBy,
            backupId: 'backup-2026-07-23',
            reason: 'Verify recovery before approving the production change.',
            grantedCapabilities: ['platform_operations.restore.request'],
        );

        $this->expectException(\DomainException::class);
        $handler->confirmRestore(
            operationId: $operation['operation_id'],
            confirmedBy: $requestedBy,
            grantedCapabilities: ['platform_operations.restore.confirm'],
        );
    }

    public function test_confirmation_dispatches_only_restore_validation_and_never_a_production_restore(): void
    {
        $gateway = new FakeBackupOperationsGateway;
        $handler = $this->handler($gateway);
        $operation = $handler->requestRestore(
            requestedBy: '0197f0e0-0000-7000-8000-000000000001',
            backupId: 'backup-2026-07-23',
            reason: 'Verify recovery before approving the production change.',
            grantedCapabilities: ['platform_operations.restore.request'],
        );

        $result = $handler->confirmRestore(
            operationId: $operation['operation_id'],
            confirmedBy: '0197f0e0-0000-7000-8000-000000000002',
            grantedCapabilities: ['platform_operations.restore.confirm'],
        );

        $this->assertSame(202, $result['http_status']);
        $this->assertSame('validation_running', $result['status']);
        $this->assertSame([], $gateway->restoreValidationRequests);
        $this->assertSame(1, $this->dispatcher($gateway)->run(10));
        $this->assertSame([[$operation['operation_id'], 'backup-2026-07-23']], $gateway->restoreValidationRequests);
    }

    public function test_completed_restore_validation_hands_off_only_the_operator_runbook_reference(): void
    {
        $gateway = new FakeBackupOperationsGateway;
        $handler = $this->handler($gateway);
        $operation = $handler->requestRestore(
            requestedBy: '0197f0e0-0000-7000-8000-000000000001',
            backupId: 'backup-2026-07-23',
            reason: 'Verify recovery before approving the production change.',
            grantedCapabilities: ['platform_operations.restore.request'],
        );
        $handler->confirmRestore(
            operationId: $operation['operation_id'],
            confirmedBy: '0197f0e0-0000-7000-8000-000000000002',
            grantedCapabilities: ['platform_operations.restore.confirm'],
        );

        $result = $handler->completeRestoreValidation($operation['operation_id'], true);

        $this->assertSame('ready_for_operator', $result['status']);
        $this->assertSame('docs/operations/ha-dr-backup.md', $result['runbook_reference']);
        $this->assertArrayNotHasKey('restore_command', $result);
    }

    public function test_unconfirmed_restore_request_can_be_cancelled_without_dispatching_validation(): void
    {
        $gateway = new FakeBackupOperationsGateway;
        $handler = $this->handler($gateway);
        $operation = $handler->requestRestore(
            requestedBy: '0197f0e0-0000-7000-8000-000000000001',
            backupId: 'backup-2026-07-23',
            reason: 'Verify recovery before approving the production change.',
            grantedCapabilities: ['platform_operations.restore.request'],
        );

        $result = $handler->cancelRestore($operation['operation_id']);

        $this->assertSame('cancelled', $result['status']);
        $this->assertSame([], $gateway->restoreValidationRequests);
    }

    public function test_backup_request_writes_a_hashed_scoped_idempotency_claim_and_outbox_without_starting_a_process(): void
    {
        $gateway = new FakeBackupOperationsGateway;
        $handler = $this->handler($gateway);

        $result = $handler->requestBackup('0197f0e0-0000-7000-8000-000000000001', 'backup-once');

        $this->assertSame([], $gateway->backupOperationIds);
        $this->assertDatabaseHas('platform_operation_requests', [
            'id' => $result['operation_id'],
            'operation_type' => 'backup',
            'idempotency_key_hash' => hash('sha256', '0197f0e0-0000-7000-8000-000000000001|backup|backup-once'),
            'dispatch_status' => 'queued',
        ]);
        $this->assertDatabaseMissing('platform_operation_requests', ['operation_payload' => json_encode(['idempotency_key' => 'backup-once'])]);
        $this->assertDatabaseHas('outbox_events', [
            'aggregate_id' => $result['operation_id'],
            'event_type' => 'com.cluster.platform-operations.backup-requested.v1',
        ]);
    }

    public function test_concurrent_loser_of_the_unique_idempotency_claim_resolves_the_existing_operation(): void
    {
        $requestedBy = '0197f0e0-0000-7000-8000-000000000001';
        $key = 'concurrent-claim';
        $operationId = '0197f0e0-0000-7000-8000-000000000099';
        DB::table('platform_operation_requests')->insert([
            'id' => $operationId,
            'operation_type' => 'backup',
            'status' => 'requested',
            'requested_by' => $requestedBy,
            'confirmed_by' => null,
            'reason' => 'on_demand_backup',
            'operation_payload' => null,
            'idempotency_key_hash' => hash('sha256', $requestedBy.'|backup|'.$key),
            'dispatch_status' => 'queued',
            'dispatch_attempts' => 0,
            'dispatch_claimed_at' => null,
            'dispatch_completed_at' => null,
            'confirmed_at' => null,
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $handler = $this->handler(new FakeBackupOperationsGateway);

        $result = $handler->requestBackup($requestedBy, $key);

        $this->assertSame($operationId, $result['operation_id']);
        $this->assertDatabaseCount('platform_operation_requests', 1);
    }

    public function test_dispatcher_claims_once_waits_for_the_gateway_and_records_completed_backup(): void
    {
        $gateway = new FakeBackupOperationsGateway;
        $handler = $this->handler($gateway);
        $operation = $handler->requestBackup('0197f0e0-0000-7000-8000-000000000001', 'dispatch-once');
        $dispatcher = $this->dispatcher($gateway);

        $this->assertSame(1, $dispatcher->run(10));
        $this->assertSame([$operation['operation_id']], $gateway->backupOperationIds);
        $this->assertDatabaseHas('platform_operation_requests', [
            'id' => $operation['operation_id'],
            'status' => 'completed',
            'dispatch_status' => 'completed',
            'dispatch_attempts' => 1,
        ]);
        $this->assertSame(0, $dispatcher->run(10));
    }

    public function test_failed_dispatch_requires_an_explicit_operator_retry_before_another_backup_is_started(): void
    {
        $gateway = new FakeBackupOperationsGateway;
        $gateway->failNextBackup = true;
        $handler = $this->handler($gateway);
        $operation = $handler->requestBackup('0197f0e0-0000-7000-8000-000000000001', 'recover-dispatch');
        $dispatcher = $this->dispatcher($gateway);

        $this->assertSame(0, $dispatcher->run(10));
        $this->assertDatabaseHas('platform_operation_requests', [
            'id' => $operation['operation_id'],
            'status' => 'failed',
            'dispatch_status' => 'reconciliation_required',
            'dispatch_attempts' => 1,
        ]);
        $replay = $handler->requestBackup('0197f0e0-0000-7000-8000-000000000001', 'recover-dispatch');
        $this->assertSame($operation['operation_id'], $replay['operation_id']);
        $this->assertDatabaseCount('platform_operation_requests', 1);
        $this->assertSame(0, $dispatcher->run(10));
        $this->assertSame([], $gateway->backupOperationIds);

        $handler->confirmDispatchRetry(
            $operation['operation_id'],
            '0197f0e0-0000-7000-8000-000000000002',
            ['platform_operations.backup.run'],
        );

        $this->assertSame(1, $dispatcher->run(10));
        $this->assertSame([$operation['operation_id']], $gateway->backupOperationIds);
        $this->assertDatabaseHas('platform_operation_requests', [
            'id' => $operation['operation_id'],
            'dispatch_status' => 'completed',
            'dispatch_attempts' => 2,
        ]);
    }

    public function test_expired_dispatch_claim_after_a_worker_crash_is_reconciled_without_a_second_backup_command(): void
    {
        config(['platform_operations.dispatch_claim_timeout_seconds' => 5]);
        $gateway = new FakeBackupOperationsGateway;
        $handler = $this->handler($gateway);
        $operation = $handler->requestBackup('0197f0e0-0000-7000-8000-000000000001', 'recover-stale-claim');
        DB::table('platform_operation_requests')->where('id', $operation['operation_id'])->update([
            'dispatch_status' => 'running',
            'dispatch_claimed_at' => now()->subSeconds(6),
        ]);

        $this->assertSame(0, $this->dispatcher($gateway)->run(10));

        $this->assertSame([], $gateway->backupOperationIds);
        $this->assertDatabaseHas('platform_operation_requests', [
            'id' => $operation['operation_id'],
            'status' => 'requested',
            'dispatch_status' => 'reconciliation_required',
            'dispatch_attempts' => 0,
        ]);
        $this->assertDatabaseCount('platform_operation_requests', 1);
    }

    public function test_bounded_artisan_dispatcher_processes_only_a_requested_batch(): void
    {
        $gateway = new FakeBackupOperationsGateway;
        $this->app->instance(BackupOperationsGateway::class, $gateway);
        $handler = $this->handler($gateway);
        $operation = $handler->requestBackup('0197f0e0-0000-7000-8000-000000000001', 'artisan-dispatch');

        $this->artisan('platform-operations:dispatch', ['--once' => true, '--limit' => '1'])
            ->assertExitCode(0);

        $this->assertSame([$operation['operation_id']], $gateway->backupOperationIds);
    }

    public function test_outbox_failure_rolls_back_operation_and_idempotency_claim(): void
    {
        $this->app->instance(TransactionalOutboxEnvelope::class, new class implements TransactionalOutboxEnvelope
        {
            public function appendEnvelope(
                string $eventId,
                string $aggregateId,
                array $cloudEvent,
                string $occurredAt,
                ?string $auditAt = null,
                OutboxDuplicatePolicy $policy = OutboxDuplicatePolicy::Strict,
            ): void {
                throw new RuntimeException('outbox unavailable');
            }
        });
        $handler = $this->handler(new FakeBackupOperationsGateway);

        try {
            $handler->requestBackup('0197f0e0-0000-7000-8000-000000000001', 'rollback-backup');
            $this->fail('Expected Outbox failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('outbox unavailable', $exception->getMessage());
        }

        $this->assertDatabaseMissing('platform_operation_requests', [
            'requested_by' => '0197f0e0-0000-7000-8000-000000000001',
            'idempotency_key_hash' => hash('sha256', '0197f0e0-0000-7000-8000-000000000001|backup|rollback-backup'),
        ]);
        $this->assertDatabaseCount('outbox_events', 0);
    }

    private function handler(BackupOperationsGateway $gateway): PlatformOperationsHandler
    {
        return new PlatformOperationsHandler(
            new EmptyHealthGateway,
            $gateway,
            $this->app->make(PlatformSettingsOutbox::class),
        );
    }

    private function dispatcher(BackupOperationsGateway $gateway): PlatformOperationsDispatchHandler
    {
        return new PlatformOperationsDispatchHandler($gateway);
    }
}

final readonly class EmptyHealthGateway implements PlatformHealthGateway
{
    public function snapshot(): array
    {
        return [];
    }
}

final class FakeBackupOperationsGateway implements BackupOperationsGateway
{
    /** @var list<string> */
    public array $backupOperationIds = [];

    public bool $failNextBackup = false;

    public bool $available = true;

    /** @var list<array{string, string}> */
    public array $restoreValidationRequests = [];

    public function status(): BackupStatus
    {
        return new BackupStatus($this->available ? 'available' : 'unconfigured', null, null, null);
    }

    public function requestBackup(string $operationId): void
    {
        if ($this->failNextBackup) {
            $this->failNextBackup = false;
            throw new \RuntimeException('backup command unavailable');
        }
        $this->backupOperationIds[] = $operationId;
    }

    public function requestRestoreValidation(string $operationId, string $backupId): void
    {
        $this->restoreValidationRequests[] = [$operationId, $backupId];
    }
}
